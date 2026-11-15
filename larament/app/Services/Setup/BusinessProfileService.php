<?php

namespace App\Services\Setup;

use App\Enums\SettingKey;
use App\Exceptions\BusinessTypeLockedException;
use App\Models\OrderTypeRow;
use App\Models\TableType;
use App\Modules\BusinessType;
use App\Modules\Module;
use App\Services\FeatureService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/**
 * First-run setup of an install: the business type, its modules and the
 * starting data that fits it (which built-in order types are active, a
 * default table type for restaurants). Used by the setup wizard and by
 * `pos:profile`. Refuses once the install has taken an order.
 */
class BusinessProfileService
{
    /** Built-in order types the profile switches on or off. */
    private const BUILT_IN_TYPES = ['dine_in', 'takeaway', 'delivery', 'companies', 'talabat', 'instant_sale'];

    public function __construct(
        private readonly FeatureService $features,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  list<Module|string>|null  $modules  null keeps the type's defaults
     *
     * @throws BusinessTypeLockedException
     */
    public function setUp(BusinessType $type, ?array $modules = null, ?string $businessName = null, ?int $actorId = null): void
    {
        DB::transaction(function () use ($type, $modules, $businessName, $actorId): void {
            $this->features->setBusinessType($type, $actorId, applyDefaultModules: $modules === null);

            if ($modules !== null) {
                $this->features->setModules($modules, $actorId);
            }

            $this->seed($type);

            if ($businessName !== null && trim($businessName) !== '') {
                $this->settings->set(SettingKey::RESTAURANT_NAME->value, trim($businessName));
            }

            $this->markCompleted();
        });
    }

    /**
     * Starting data for the type. Safe to run again: it only switches the
     * built-in order types and adds a table type when none exists.
     *
     * @throws BusinessTypeLockedException
     */
    public function seed(BusinessType $type): void
    {
        if ($this->features->businessTypeLocked()) {
            throw new BusinessTypeLockedException;
        }

        $active = $type->defaultOrderTypes();

        OrderTypeRow::query()
            ->whereIn('code', self::BUILT_IN_TYPES)
            ->get()
            ->each(function (OrderTypeRow $row) use ($active): void {
                $row->update(['is_active' => in_array($row->code, $active, true)]);
            });

        if (in_array(Module::RESTAURANT, $type->defaultModules(), true) && ! TableType::query()->exists()) {
            TableType::query()->create(['name' => 'صالة']);
        }
    }

    public function completed(): bool
    {
        return $this->settings->getBoolean(SettingKey::SETUP_COMPLETED);
    }

    public function markCompleted(): void
    {
        $this->settings->set(SettingKey::SETUP_COMPLETED->value, true);
    }
}
