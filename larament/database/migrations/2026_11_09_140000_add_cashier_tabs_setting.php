<?php

use App\Enums\SettingKey;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Setting::firstOrCreate(
            ['key' => SettingKey::CASHIER_TABS->value],
            ['value' => SettingKey::CASHIER_TABS->defaultValue()],
        );
    }

    public function down(): void
    {
        Setting::query()->where('key', SettingKey::CASHIER_TABS->value)->delete();
    }
};
