<?php

use App\Enums\SettingKey;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Setting::updateOrCreate(
            ['key' => SettingKey::UPDATE_PRODUCT_COST_ON_PURCHASE->value],
            [
                'key' => SettingKey::UPDATE_PRODUCT_COST_ON_PURCHASE->value,
                'value' => SettingKey::UPDATE_PRODUCT_COST_ON_PURCHASE->defaultValue(),
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Setting::where('key', SettingKey::UPDATE_PRODUCT_COST_ON_PURCHASE->value)->delete();
    }
};
