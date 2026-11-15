<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_types', function (Blueprint $table) {
            $table->string('guests_mode', 20)->default('off')->after('require_customer_for_credit');
        });

        // Dine-in asks for the number of guests, without forcing it.
        DB::table('order_types')->where('behavior', 'dine_in')->update(['guests_mode' => 'optional']);
    }

    public function down(): void
    {
        Schema::table('order_types', function (Blueprint $table) {
            $table->dropColumn('guests_mode');
        });
    }
};
