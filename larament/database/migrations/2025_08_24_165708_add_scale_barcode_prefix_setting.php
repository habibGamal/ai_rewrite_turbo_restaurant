<?php

use App\Enums\SettingKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Insert the new scale barcode prefix setting with default value
        DB::table('settings')->insertOrIgnore([
            'key' => SettingKey::SCALE_BARCODE_PREFIX->value,
            'value' => SettingKey::SCALE_BARCODE_PREFIX->defaultValue(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove the scale barcode prefix setting
        DB::table('settings')->where('key', SettingKey::SCALE_BARCODE_PREFIX->value)->delete();
    }
};
