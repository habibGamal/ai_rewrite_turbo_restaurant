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
        // Insert the new cashier permission settings
        $settings = [
            [
                'key' => 'allow_cashier_discounts',
                'value' => 'false',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'allow_cashier_cancel_orders',
                'value' => 'false',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'allow_cashier_item_changes',
                'value' => 'false',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove the cashier permission settings
        Setting::whereIn('key', [
            'allow_cashier_discounts',
            'allow_cashier_cancel_orders',
            'allow_cashier_item_changes',
        ])->delete();
    }
};
