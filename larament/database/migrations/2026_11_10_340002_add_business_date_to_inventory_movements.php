<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_item_movements', function (Blueprint $table) {
            $table->date('business_date')->nullable()->index();
        });

        DB::table('inventory_item_movements')
            ->whereNull('business_date')
            ->update(['business_date' => DB::raw('COALESCE(DATE(created_at), CURRENT_DATE)')]);
    }

    public function down(): void
    {
        Schema::table('inventory_item_movements', function (Blueprint $table) {
            $table->dropIndex(['business_date']);
            $table->dropColumn('business_date');
        });
    }
};
