<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Setting::firstOrCreate(
            ['key' => 'kds_cashier_status_enabled'],
            ['value' => 'true']
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Setting::query()->where('key', 'kds_cashier_status_enabled')->delete();
    }
};
