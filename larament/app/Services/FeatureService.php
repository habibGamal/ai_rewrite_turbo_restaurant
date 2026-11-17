<?php

namespace App\Services;

use App\Enums\OptionalFeature;
use App\Enums\SettingKey;
use App\Exceptions\BusinessTypeLockedException;
use App\Exceptions\OrderException;
use App\Models\FeatureFlagAudit;
use App\Models\OrderTypeRow;
use App\Models\Setting;
use App\Modules\BusinessType;
use App\Modules\Module;
use App\Orders\OrderTypeCatalog;
use App\Orders\TabPermissions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class FeatureService
{
    public const INSTANT_SALE_TYPE = 'instant_sale';

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

    /**
     * Disable blocker callbacks for modules, keyed by module value.
     *
     * @var array<string, array<int, callable(): (array<int, string>|string|null)>>
     */
    private array $moduleDisableBlockers = [];

    public function __construct(
        private readonly SettingsService $settingsService,
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

    public function multiTerminalEnabled(): bool
    {
        return $this->isEnabled(OptionalFeature::MULTI_TERMINAL);
    }

    public function variantsEnabled(): bool
    {
        return $this->isEnabled(OptionalFeature::VARIANTS);
    }

    public function purchaseBarcodesEnabled(): bool
    {
        return $this->isEnabled(OptionalFeature::PURCHASE_BARCODES);
    }

    public function labelPrintingEnabled(): bool
    {
        return $this->isEnabled(OptionalFeature::LABEL_PRINTING);
    }

    public function documentBarcodesEnabled(): bool
    {
        return $this->isEnabled(OptionalFeature::DOCUMENT_BARCODES);
    }

    public function deferredPaymentEnabled(): bool
    {
        return $this->isEnabled(OptionalFeature::DEFERRED_PAYMENT);
    }

    public function returnsEnabled(): bool
    {
        return $this->isEnabled(OptionalFeature::RETURNS);
    }

    /**
     * The manager may reopen a closed shift's money (add, edit or delete its
     * expenses and revenues) from the shift money page.
     */
    public function closedShiftEditsEnabled(): bool
    {
        return $this->isEnabled(OptionalFeature::CLOSED_SHIFT_EDITS);
    }

    /**
     * Sales points work as separate branches. Only takes effect while
     * multiple terminals are on.
     */
    public function terminalSeparationEnabled(): bool
    {
        return $this->multiTerminalEnabled() && $this->isEnabled(OptionalFeature::TERMINAL_SEPARATION);
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
     * @return array{revenues: bool, custom_fees: bool, outsourced_orders: bool, equipment: bool, variants: bool, multi_terminal: bool, terminal_separation: bool, deferred_payment: bool, returns: bool}
     */
    public function toInertiaProps(): array
    {
        return [
            'revenues' => $this->revenuesEnabled(),
            'custom_fees' => $this->customFeesEnabled(),
            'outsourced_orders' => $this->outsourcedOrdersEnabled(),
            'equipment' => $this->equipmentEnabled(),
            'variants' => $this->variantsEnabled(),
            'multi_terminal' => $this->multiTerminalEnabled(),
            'terminal_separation' => $this->terminalSeparationEnabled(),
            'deferred_payment' => $this->deferredPaymentEnabled(),
            'returns' => $this->returnsEnabled(),
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
        $blockers = [];

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
        $module = Module::forPermission($permission);
        if ($module !== null && ! $this->moduleEnabled($module)) {
            return true;
        }

        if (str_starts_with($permission, TabPermissions::TYPE_PREFIX)) {
            return in_array($permission, $this->hiddenOrderTypeTabPermissions(), true);
        }

        $feature = $this->featureForPermission($permission);

        return $feature !== null && ! $this->isEnabled($feature);
    }

    /**
     * Cashier tab permissions of order types whose behaviour belongs to a
     * switched-off module (for example the dine-in tab of a clothing shop).
     *
     * @return list<string>
     */
    private function hiddenOrderTypeTabPermissions(): array
    {
        if (! Schema::hasTable('order_types')) {
            return [];
        }

        $catalog = app(OrderTypeCatalog::class);

        return $catalog->all()
            ->reject(fn (OrderTypeRow $row): bool => $catalog->isAvailable($row))
            ->map(fn (OrderTypeRow $row): string => TabPermissions::forTypeCode($row->code))
            ->values()
            ->all();
    }

    /**
     * Return all permissions associated with currently disabled optional features
     * and switched-off modules.
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

        foreach (Module::cases() as $module) {
            if (! $this->moduleEnabled($module)) {
                $disabled = array_merge($disabled, $module->permissions());
            }
        }

        $disabled = array_merge($disabled, $this->hiddenOrderTypeTabPermissions());

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

    // ---------------------------------------------------------------------
    // Business type and modules (vendor-owned)
    // ---------------------------------------------------------------------

    public function businessType(): BusinessType
    {
        $stored = $this->settingsService->get(SettingKey::BUSINESS_TYPE->value);

        return BusinessType::tryFrom((string) $stored) ?? BusinessType::RESTAURANT;
    }

    /**
     * The business type is fixed once the install has taken its first order.
     */
    public function businessTypeLocked(): bool
    {
        return Schema::hasTable('orders') && DB::table('orders')->exists();
    }

    /**
     * Set the business type and, by default, switch the modules to the
     * type's defaults. Refuses once any order exists.
     *
     * @throws BusinessTypeLockedException
     */
    public function setBusinessType(BusinessType $type, ?int $actorId = null, bool $applyDefaultModules = true): void
    {
        DB::transaction(function () use ($type, $actorId, $applyDefaultModules): void {
            $key = SettingKey::BUSINESS_TYPE->value;
            $row = Setting::query()->where('key', $key)->lockForUpdate()->first();
            $old = BusinessType::tryFrom((string) $row?->value) ?? BusinessType::RESTAURANT;

            if ($this->businessTypeLocked()) {
                throw new BusinessTypeLockedException;
            }

            Setting::query()->updateOrCreate(['key' => $key], ['value' => $type->value]);
            Setting::invalidateCache($key);

            if ($old !== $type || $row === null) {
                $this->audit($key, null, null, $actorId, $old->value, $type->value);
            }

            if ($applyDefaultModules) {
                // External sellers is never a default; the profile does not
                // switch off sellers the vendor already turned on.
                $modules = $type->defaultModules();
                if ($this->outsourcedOrdersEnabled()) {
                    $modules[] = Module::EXTERNAL_SELLERS;
                }

                $this->setModules($modules, $actorId);
            }

            // Instant sale is the default counter for every business except
            // restaurants, which turn it on themselves for counter sales.
            if ($old !== $type) {
                OrderTypeRow::query()
                    ->where('code', self::INSTANT_SALE_TYPE)
                    ->update(['is_active' => $type !== BusinessType::RESTAURANT]);
            }

            Setting::invalidateCache();
        });
    }

    /**
     * @return list<Module>
     */
    public function enabledModules(): array
    {
        $stored = $this->settingsService->get(SettingKey::ENABLED_MODULES->value);

        return $this->withExternalSellers($this->decodeModules($stored), $this->outsourcedOrdersEnabled());
    }

    /**
     * The External sellers module is switched by the outsourced-orders flag
     * that installs already had before modules existed: one switch, kept in
     * its original setting, with its own disable blockers (unsettled seller
     * money) and audit rows. Its entry in the module list is ignored.
     *
     * @param  list<Module>  $modules
     * @return list<Module>
     */
    private function withExternalSellers(array $modules, bool $enabled): array
    {
        return array_values(array_filter(
            Module::cases(),
            fn (Module $module): bool => $module === Module::EXTERNAL_SELLERS
                ? $enabled
                : in_array($module, $modules, true),
        ));
    }

    public function moduleEnabled(Module $module): bool
    {
        return in_array($module, $this->enabledModules(), true);
    }

    public function enableModule(Module $module, ?int $actorId = null): void
    {
        $this->setModules([...$this->enabledModules(), $module], $actorId);
    }

    public function disableModule(Module $module, ?int $actorId = null): void
    {
        $this->setModules(
            array_values(array_filter($this->enabledModules(), fn (Module $m): bool => $m !== $module)),
            $actorId,
        );
    }

    /**
     * Replace the enabled module list. Runs the disable blockers of every
     * module being switched off and writes one audit row per change.
     *
     * @param  array<int, Module|string>  $modules
     * @return array<string, array{old: bool, new: bool}>
     */
    public function setModules(array $modules, ?int $actorId = null): array
    {
        $target = [];
        foreach ($modules as $module) {
            $resolved = $module instanceof Module ? $module : Module::tryFrom((string) $module);
            if ($resolved === null) {
                throw ValidationException::withMessages(['modules' => 'وحدة غير معروفة: '.$module]);
            }
            $target[$resolved->value] = $resolved;
        }

        return DB::transaction(function () use ($target, $actorId): array {
            $key = SettingKey::ENABLED_MODULES->value;
            $row = Setting::query()->where('key', $key)->lockForUpdate()->first();
            $current = $this->withExternalSellers($this->decodeModules($row?->value), $this->outsourcedOrdersEnabled());

            // External sellers is stored as the outsourced-orders flag; that
            // switch runs its own blockers and writes its own audit row.
            $sellersOld = in_array(Module::EXTERNAL_SELLERS, $current, true);
            $sellersNew = isset($target[Module::EXTERNAL_SELLERS->value]);
            if ($sellersOld !== $sellersNew) {
                $this->updateFeatures([OptionalFeature::OUTSOURCED_ORDERS->value => $sellersNew], $actorId);
            }

            $errors = [];
            $changes = $sellersOld !== $sellersNew
                ? [Module::EXTERNAL_SELLERS->value => ['old' => $sellersOld, 'new' => $sellersNew]]
                : [];
            foreach (Module::cases() as $module) {
                if ($module === Module::EXTERNAL_SELLERS) {
                    continue;
                }

                $old = in_array($module, $current, true);
                $new = isset($target[$module->value]);

                if ($old && ! $new) {
                    $blockers = $this->checkModuleDisableBlockers($module);
                    if ($blockers !== []) {
                        $errors['modules.'.$module->value] = implode(' ', $blockers);

                        continue;
                    }
                }

                if ($old !== $new) {
                    $changes[$module->value] = ['old' => $old, 'new' => $new];
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $ordered = array_values(array_filter(
                array_map(fn (Module $module): string => $module->value, Module::cases()),
                fn (string $value): bool => isset($target[$value]) && $value !== Module::EXTERNAL_SELLERS->value,
            ));

            Setting::query()->updateOrCreate(['key' => $key], ['value' => json_encode($ordered)]);
            Setting::invalidateCache($key);

            foreach ($changes as $moduleValue => $change) {
                if ($moduleValue === Module::EXTERNAL_SELLERS->value) {
                    continue;
                }

                $this->audit(Module::from($moduleValue)->auditKey(), $change['old'], $change['new'], $actorId);
            }

            Setting::invalidateCache();

            return $changes;
        });
    }

    /**
     * @param  callable(): (array<int, string>|string|null)  $checker
     */
    public function registerModuleDisableBlocker(Module $module, callable $checker): void
    {
        $this->moduleDisableBlockers[$module->value][] = $checker;
    }

    /**
     * @return array<int, string>
     */
    public function checkModuleDisableBlockers(Module $module): array
    {
        $blockers = [];
        foreach ($this->moduleDisableBlockers[$module->value] ?? [] as $checker) {
            $result = $checker();
            foreach ((array) $result as $message) {
                if (is_string($message) && $message !== '') {
                    $blockers[] = $message;
                }
            }
        }

        return array_values(array_unique($blockers));
    }

    /**
     * Shared Inertia props for modules and the business type.
     *
     * @return array{modules: list<string>, business_type: string}
     */
    public function moduleInertiaProps(): array
    {
        return [
            'modules' => array_map(fn (Module $module): string => $module->value, $this->enabledModules()),
            'business_type' => $this->businessType()->value,
        ];
    }

    /**
     * @return list<Module>
     */
    private function decodeModules(mixed $stored): array
    {
        if ($stored === null || $stored === '') {
            $stored = SettingKey::ENABLED_MODULES->defaultValue();
        }

        $decoded = is_array($stored) ? $stored : json_decode((string) $stored, true);
        if (! is_array($decoded)) {
            return [];
        }

        $modules = [];
        foreach ($decoded as $value) {
            $module = is_string($value) ? Module::tryFrom($value) : null;
            if ($module !== null && ! in_array($module, $modules, true)) {
                $modules[] = $module;
            }
        }

        return $modules;
    }

    private function audit(
        string $key,
        ?bool $old,
        ?bool $new,
        ?int $actorId,
        ?string $oldText = null,
        ?string $newText = null,
    ): void {
        $resolvedActorId = $actorId ?? Auth::id();
        $now = now();

        FeatureFlagAudit::create([
            'feature_key' => $key,
            'old_value' => $old,
            'new_value' => $new,
            'old_text' => $oldText,
            'new_text' => $newText,
            'user_id' => $resolvedActorId,
            'changed_at' => $now,
        ]);

        Log::info('Vendor setting changed', [
            'key' => $key,
            'old' => $oldText ?? $old,
            'new' => $newText ?? $new,
            'user_id' => $resolvedActorId,
            'changed_at' => $now->toIso8601String(),
        ]);
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
