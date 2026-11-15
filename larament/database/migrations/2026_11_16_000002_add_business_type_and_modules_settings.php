<?php

use App\Enums\OrderBehavior;
use App\Enums\SettingKey;
use App\Modules\BusinessType;
use App\Modules\Module;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 of the modular core: every existing install is a restaurant, and
 * keeps exactly the modules it uses today, so nothing visible changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $now = now();

        $this->insertIfMissing(SettingKey::BUSINESS_TYPE->value, BusinessType::RESTAURANT->value, $now);
        $this->insertIfMissing(SettingKey::ENABLED_MODULES->value, json_encode($this->existingInstallModules()), $now);

        Cache::forget('settings.'.SettingKey::BUSINESS_TYPE->value);
        Cache::forget('settings.'.SettingKey::ENABLED_MODULES->value);
        Cache::forget('settings.all');
    }

    public function down(): void
    {
        if (Schema::hasTable('settings')) {
            DB::table('settings')
                ->whereIn('key', [SettingKey::BUSINESS_TYPE->value, SettingKey::ENABLED_MODULES->value])
                ->delete();
        }

        Cache::forget('settings.all');
    }

    /**
     * Restaurant, Delivery and Manufacturing, plus Web orders when an active
     * order type uses a web behaviour, plus External sellers when the
     * outsourced-orders feature is on today.
     *
     * @return list<string>
     */
    private function existingInstallModules(): array
    {
        $modules = BusinessType::RESTAURANT->defaultModules();

        if (Schema::hasTable('order_types') && DB::table('order_types')
            ->where('is_active', true)
            ->whereIn('behavior', [OrderBehavior::WEB_DELIVERY->value, OrderBehavior::WEB_TAKEAWAY->value])
            ->exists()) {
            $modules[] = Module::WEB_ORDERS;
        }

        $outsourced = DB::table('settings')
            ->where('key', SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED->value)
            ->value('value');
        if (filter_var($outsourced, FILTER_VALIDATE_BOOLEAN)) {
            $modules[] = Module::EXTERNAL_SELLERS;
        }

        $values = array_map(fn (Module $module): string => $module->value, $modules);

        // Keep enum order so the stored list is stable.
        return array_values(array_filter(
            array_map(fn (Module $module): string => $module->value, Module::cases()),
            fn (string $value): bool => in_array($value, $values, true),
        ));
    }

    private function insertIfMissing(string $key, string $value, mixed $now): void
    {
        if (DB::table('settings')->where('key', $key)->exists()) {
            return;
        }

        DB::table('settings')->insert([
            'key' => $key,
            'value' => $value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
