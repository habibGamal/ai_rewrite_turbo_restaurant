<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The website's stable idempotency key for a web order. A retried place-order
 * call with the same key is answered with the order the POS already saved
 * instead of a duplicate-key error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('web_order_key', 100)->nullable()->unique()->after('order_number');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['web_order_key']);
            $table->dropColumn('web_order_key');
        });
    }
};
