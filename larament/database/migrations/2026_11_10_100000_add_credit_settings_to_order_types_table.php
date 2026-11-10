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
            $table->boolean('allow_partial_payment')->default(false)->after('behavior');
            $table->boolean('allow_pay_later')->default(false)->after('allow_partial_payment');
            $table->boolean('require_customer_for_credit')->default(true)->after('allow_pay_later');
        });

        DB::table('order_types')->where('code', 'companies')->update([
            'allow_partial_payment' => true,
            'allow_pay_later' => true,
        ]);
    }

    public function down(): void
    {
        Schema::table('order_types', function (Blueprint $table) {
            $table->dropColumn([
                'allow_partial_payment',
                'allow_pay_later',
                'require_customer_for_credit',
            ]);
        });
    }
};
