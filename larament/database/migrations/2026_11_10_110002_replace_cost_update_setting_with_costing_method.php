<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $old = DB::table('settings')->where('key', 'update_product_cost_on_purchase')->value('value');
        DB::table('settings')->updateOrInsert(['key' => 'costing_method'], [
            'value' => $old === null || filter_var($old, FILTER_VALIDATE_BOOLEAN) ? 'immediate' : 'off',
        ]);
        DB::table('settings')->where('key', 'update_product_cost_on_purchase')->delete();
    }

    public function down(): void
    {
        $method = DB::table('settings')->where('key', 'costing_method')->value('value');
        DB::table('settings')->updateOrInsert(['key' => 'update_product_cost_on_purchase'], ['value' => $method === 'off' ? 'false' : 'true']);
        DB::table('settings')->where('key', 'costing_method')->delete();
    }
};
