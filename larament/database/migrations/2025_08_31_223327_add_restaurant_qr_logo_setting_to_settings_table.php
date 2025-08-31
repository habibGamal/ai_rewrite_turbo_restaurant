<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Setting;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Insert the new restaurant QR logo setting
        Setting::updateOrCreate(
            ['key' => 'restaurant_qr_logo'],
            [
                'key' => 'restaurant_qr_logo',
                'value' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove the restaurant QR logo setting
        Setting::where('key', 'restaurant_qr_logo')->delete();
    }
};
