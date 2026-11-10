<?php

namespace App\Services;

use App\Enums\OptionalFeature;
use App\Enums\OrderStatus;
use App\Enums\SettingKey;
use App\Exceptions\OrderException;
use App\Models\ExternalOrder;
use App\Models\ExternalSeller;
use App\Models\FeatureFlagAudit;
use App\Models\Setting;
use App\Services\Orders\ExternalSellerSettlementService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class FeatureService
{
    /**
     * Custom disable blocker callbacks keyed by feature value.
     *
     * @var array<string, array<int, callable(): (array<int, string>|string|null)>>
     */
    private array $disableBlockers = [];

    /**
     * Additional permissions registered dynamically per feature.
     *
     * @var array<string, array<int, string>>
     */
    private array $extraPermissions = [];

    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly ExternalSellerSettlementService $settlementService,
    ) {}

    public function isEnabled(OptionalFeature|SettingKey|string $feature): bool
    {
        $resolved = $this->resolveFeature($feature);

        return $this->settingsService->getBoolean($resolved->settingKey());
    }

    public function revenuesEnabled(): bool
    {
        return $this->isEnabled(OptionalFeature::REVENUES);
    }

    public function customFeesEnabled(): bool
    {
        return $this->isEnabled(OptionalFeature::CUSTOM_FEES);
    }

    public function outsourcedOrdersEnabled(): bool
    {
        return $this->isEnabled(OptionalFeature::OUTSOURCED_ORDERS);
    }

    public function equipmentEnabled(): bool
    {
        return $this->isEnabled(OptionalFeature::EQUIPMENT);
    }

    /**
     * @return array<string, bool>
     */
    public function all(): array
    {
        $states = [];
        foreach (OptionalFeature::cases() as $feature) {
            $states[$feature->value] = $this->isEnabled($feature);
        }

        return $states;
    }

    /**
     * Shared Inertia props for optional features using typed short keys.
     *
     * @return array{revenues: bool, custom_fees: bool, outsourced_orders: bool, equipment: bool}
     */
    public function toInertiaProps(): array
    {
        return [
            'revenues' => $this->revenuesEnabled(),
            'custom_fees' => $this->customFeesEnabled(),
            'outsourced_orders' => $this->outsourcedOrdersEnabled(),
            'equipment' => $this->equipmentEnabled(),
        ];
    }

    public function assertEnabled(OptionalFeature|SettingKey|string $feature, ?string $message = null): void
    {
        $resolved = $this->resolveFeature($feature);

        if (! $this->isEnabled($resolved)) {
            throw new OrderException($message ?? $resolved->disabledErrorMessage());
        }
    }

    /**
     * Lock the setting row inside an active DB transaction and assert the feature is enabled.
     */
    public function lockAndAssertEnabled(OptionalFeature|SettingKey|string $feature, ?string $message = null): void
    {
        $resolved = $this->resolveFeature($feature);
        $key = $resolved->value;

        $setting = Setting::query()->where('key', $key)->lockForUpdate()->first();
        $rawValue = $setting?->value !== null && $setting->value !== ''
            ? $setting->value
            : $resolved->settingKey()->defaultValue();

        if (! filter_var($rawValue, FILTER_VALIDATE_BOOLEAN)) {
            throw new OrderException($message ?? $resolved->disabledErrorMessage());
        }
    }

    public function abortUnlessEnabled(OptionalFeature|SettingKey|string $feature, int $status = 403): void
    {
        $resolved = $this->resolveFeature($feature);

        abort_unless($this->isEnabled($resolved), $status);
    }

    public function enable(OptionalFeature|SettingKey|string $feature, ?int $actorId = null): void
    {
        $this->switchFeature($feature, true, $actorId);
    }

    public function disable(OptionalFeature|SettingKey|string $feature, ?int $actorId = null): void
    {
        $this->switchFeature($feature, false, $actorId);
    }

    public function switchFeature(OptionalFeature|SettingKey|string $feature, bool $enabled, ?int $actorId = null): bool
    {
        $resolved = $this->resolveFeature($feature);
        $changes = $this->updateFeatures([$resolved->value => $enabled], $actorId);

        return isset($changes[$resolved->value]);
    }

    /**
     * Guarded batch update of feature flags with strict boolean validation, blocker checks, and audit logging.
     *
     * @param  array<string, mixed>  $desiredStates
     * @return array<string, array{old: bool, new: bool}>
     */
    public function updateFeatures(array $desiredStates, ?int $actorId = null): array
    {
        if ($desiredStates === []) {
            return [];
        }

        return DB::transaction(function () use ($desiredStates, $actorId): array {
            $normalizedTargets = [];
            foreach ($desiredStates as $key => $value) {
                $feature = $this->resolveFeature((string) $key);
                $normalizedTargets[$feature->value] = SettingKey::parseStoredBoolean($feature->value, $value);
            }

            $existingRows = Setting::query()
                ->whereIn('key', array_keys($normalizedTargets))
                ->lockForUpdate()
                ->get()
                ->keyBy('key');

            $errors = [];
            $transitions = [];

            foreach ($normalizedTargets as $featureKey => $newValue) {
                $feature = OptionalFeature::from($featureKey);
                $existingRow = $existingRows->get($featureKey);
                $rawOld = $existingRow?->value !== null && $existingRow->value !== ''
                    ? $existingRow->value
                    : $feature->settingKey()->defaultValue();
                $oldValue = filter_var($rawOld, FILTER_VALIDATE_BOOLEAN);

                if ($oldValue && ! $newValue) {
                    $blockers = $this->checkDisableBlockers($feature);
                    if ($blockers !== []) {
                        $message = implode(' ', $blockers);
                        $errors['data.'.$featureKey] = $message;
                        $errors[$featureKey] = $message;

                        continue;
                    }
                }

                $transitions[$featureKey] = [
                    'feature' => $feature,
                    'old' => $oldValue,
                    'new' => $newValue,
                    'changed' => $oldValue !== $newValue,
                ];
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $appliedChanges = [];
            $resolvedActorId = $actorId ?? Auth::id();
            $now = now();

            foreach ($transitions as $featureKey => $transition) {
                Setting::query()->updateOrCreate(
                    ['key' => $featureKey],
                    ['value' => $transition['new'] ? 'true' : 'false']
                );
                Setting::invalidateCache($featureKey);

                if ($transition['changed']) {
                    $appliedChanges[$featureKey] = [
                        'old' => $transition['old'],
                        'new' => $transition['new'],
                    ];

                    FeatureFlagAudit::create([
                        'feature_key' => $featureKey,
                        'old_value' => $transition['old'],
                        'new_value' => $transition['new'],
                        'user_id' => $resolvedActorId,
                        'changed_at' => $now,
                    ]);

                    Log::info('Optional feature flag changed', [
                        'feature_key' => $featureKey,
                        'old_value' => $transition['old'],
                        'new_value' => $transition['new'],
                        'user_id' => $resolvedActorId,
                        'changed_at' => $now->toIso8601String(),
                    ]);
                }
            }

            Setting::invalidateCache();

            return $appliedChanges;
        });
    }

    /**
     * Register a disable-transition blocker callback for a feature.
     *
     * @param  callable(): (array<int, string>|string|null)  $checker
     */
    public function registerDisableBlocker(OptionalFeature|SettingKey|string $feature, callable $checker): void
    {
        $resolved = $this->resolveFeature($feature);
        $this->disableBlockers[$resolved->value][] = $checker;
    }

    public function clearDisableBlockers(?OptionalFeature $feature = null): void
    {
        if ($feature === null) {
            $this->disableBlockers = [];

            return;
        }

        unset($this->disableBlockers[$feature->value]);
    }

    /**
     * Check all active blockers preventing a feature from transitioning ON -> OFF.
     *
     * @return array<int, string>
     */
    public function checkDisableBlockers(OptionalFeature $feature): array
    {
        $blockers = $feature === OptionalFeature::OUTSOURCED_ORDERS
            ? $this->checkOutsourcedOrdersDisableBlockers()
            : [];

        foreach ($this->disableBlockers[$feature->value] ?? [] as $checker) {
            $result = $checker();
            if (is_string($result) && $result !== '') {
                $blockers[] = $result;
            } elseif (is_array($result)) {
                foreach ($result as $message) {
                    if ($message !== '') {
                        $blockers[] = $message;
                    }
                }
            }
        }

        return array_values(array_unique($blockers));
    }

    /**
     * Register additional permissions belonging to an optional feature.
     *
     * @param  array<int, string>  $permissions
     */
    public function registerFeaturePermissions(OptionalFeature|SettingKey|string $feature, array $permissions): void
    {
        $resolved = $this->resolveFeature($feature);
        $existing = $this->extraPermissions[$resolved->value] ?? [];
        $this->extraPermissions[$resolved->value] = array_values(array_unique(array_merge($existing, $permissions)));
    }

    /**
     * @return array<int, string>
     */
    public function permissionsFor(OptionalFeature $feature): array
    {
        return array_values(array_unique(array_merge(
            $feature->permissions(),
            $this->extraPermissions[$feature->value] ?? [],
        )));
    }

    /**
     * Resolve the optional feature associated with a permission name in memory without querying the database.
     */
    public function featureForPermission(string $permission): ?OptionalFeature
    {
        foreach (OptionalFeature::cases() as $feature) {
            if (in_array($permission, $this->permissionsFor($feature), true)) {
                return $feature;
            }
        }

        return null;
    }

    /**
     * Check whether a permission belongs to a currently disabled feature (queries DB only if permission is feature-gated).
     */
    public function isPermissionDisabled(string $permission): bool
    {
        $feature = $this->featureForPermission($permission);

        return $feature !== null && ! $this->isEnabled($feature);
    }

    /**
     * Return all permissions associated with currently disabled optional features.
     *
     * @return array<int, string>
     */
    public function disabledFeaturePermissions(): array
    {
        $disabled = [];
        foreach (OptionalFeature::cases() as $feature) {
            $permissions = $this->permissionsFor($feature);
            if ($permissions !== [] && ! $this->isEnabled($feature)) {
                $disabled = array_merge($disabled, $permissions);
            }
        }

        return array_values(array_unique($disabled));
    }

    /**
     * Filter disabled-feature permissions out of a grouped permission catalog.
     *
     * @param  array<string, array<int|string, string>>  $catalog
     * @return array<string, array<int|string, string>>
     */
    public function filterPermissionCatalog(array $catalog): array
    {
        $hidden = $this->disabledFeaturePermissions();
        if ($hidden === []) {
            return $catalog;
        }

        $hiddenLookup = array_flip($hidden);
        $filtered = [];

        foreach ($catalog as $group => $permissions) {
            if (array_is_list($permissions)) {
                $visible = array_values(array_diff($permissions, $hidden));
            } else {
                $visible = array_diff_key($permissions, $hiddenLookup);
            }

            if ($visible !== []) {
                $filtered[$group] = $visible;
            }
        }

        return $filtered;
    }

    /**
     * Preserve permissions on a role that belong to currently disabled features when saving visible permissions.
     *
     * @param  array<int, string>  $selectedPermissions
     * @return array<int, string>
     */
    public function preserveHiddenRolePermissions(Role $role, array $selectedPermissions): array
    {
        $disabledPermissions = $this->disabledFeaturePermissions();
        if ($disabledPermissions === []) {
            return array_values(array_unique($selectedPermissions));
        }

        $currentRolePermissions = $role->permissions()->pluck('name')->all();
        $preservedHidden = array_intersect($currentRolePermissions, $disabledPermissions);

        return array_values(array_unique(array_merge($selectedPermissions, $preservedHidden)));
    }

    /**
     * @return array<int, string>
     */
    protected function checkOutsourcedOrdersDisableBlockers(): array
    {
        $blockers = [];

        if (Schema::hasTable('external_orders') && Schema::hasTable('orders')) {
            $openExternalOrdersCount = ExternalOrder::query()
                ->where('status', '!=', 'cancelled')
                ->whereHas('host', fn ($query) => $query->whereIn('status', [
                    OrderStatus::PENDING,
                    OrderStatus::PROCESSING,
                    OrderStatus::OUT_FOR_DELIVERY,
                ]))
                ->lockForUpdate()
                ->count();

            if ($openExternalOrdersCount > 0) {
                $blockers[] = "لا يمكن إيقاف ميزة الطلبات الخارجية لوجود {$openExternalOrdersCount} طلب خارجي قيد التشغيل على طلبات مفتوحة.";
            }
        }

        if (Schema::hasTable('external_sellers')) {
            $sellers = ExternalSeller::query()->lockForUpdate()->get();
            $unsettledSellers = $sellers->filter(
                fn (ExternalSeller $seller): bool => abs($this->settlementService->payable($seller)) > 0.0001
            );

            if ($unsettledSellers->isNotEmpty()) {
                $names = $unsettledSellers->pluck('name')->implode('، ');
                $blockers[] = "لا يمكن إيقاف ميزة الطلبات الخارجية لوجود مستحقات غير مسواة للمتاجر المتعاونة ({$names}).";
            }
        }

        return $blockers;
    }

    private function resolveFeature(OptionalFeature|SettingKey|string $feature): OptionalFeature
    {
        $resolved = OptionalFeature::fromKey($feature);

        if ($resolved === null) {
            throw new \InvalidArgumentException('Unknown optional feature: '.($feature instanceof \BackedEnum ? $feature->value : $feature));
        }

        return $resolved;
    }
}
