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
        Setting::updateOrCreate(
            ['key' => 'update_product_cost_on_purchase'],
            [
                'key' => 'update_product_cost_on_purchase',
                'value' => 'true',
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Setting::where('key', 'update_product_cost_on_purchase')->delete();
    }
};
