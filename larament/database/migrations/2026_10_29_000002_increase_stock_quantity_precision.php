<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->decimal('quantity', 12, 3)->default(0)->change();
        });

        Schema::table('inventory_item_movements', function (Blueprint $table) {
            $table->decimal('quantity', 12, 3)->change();
        });

        Schema::table('inventory_item_movement_daily', function (Blueprint $table) {
            $table->decimal('start_quantity', 12, 3)->default(0)->change();
            $table->decimal('incoming_quantity', 12, 3)->default(0)->change();
            $table->decimal('return_sales_quantity', 12, 3)->default(0)->change();
            $table->decimal('sales_quantity', 12, 3)->default(0)->change();
            $table->decimal('return_waste_quantity', 12, 3)->default(0)->change();
            $table->decimal('end_quantity', 12, 3)->default(0)->change();
        });

        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->decimal('quantity', 12, 3)->change();
        });

        Schema::table('return_purchase_invoice_items', function (Blueprint $table) {
            $table->decimal('quantity', 12, 3)->change();
        });

        Schema::table('wasted_items', function (Blueprint $table) {
            $table->decimal('quantity', 12, 3)->change();
        });

        Schema::table('stocktaking_items', function (Blueprint $table) {
            $table->decimal('stock_quantity', 12, 3)->change();
            $table->decimal('real_quantity', 12, 3)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stocktaking_items', function (Blueprint $table) {
            $table->decimal('stock_quantity', 10, 2)->change();
            $table->decimal('real_quantity', 10, 2)->change();
        });

        Schema::table('wasted_items', function (Blueprint $table) {
            $table->decimal('quantity', 10, 2)->change();
        });

        Schema::table('return_purchase_invoice_items', function (Blueprint $table) {
            $table->decimal('quantity', 10, 2)->change();
        });

        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->decimal('quantity', 10, 2)->change();
        });

        Schema::table('inventory_item_movement_daily', function (Blueprint $table) {
            $table->decimal('start_quantity', 10, 2)->default(0)->change();
            $table->decimal('incoming_quantity', 10, 2)->default(0)->change();
            $table->decimal('return_sales_quantity', 10, 2)->default(0)->change();
            $table->decimal('sales_quantity', 10, 2)->default(0)->change();
            $table->decimal('return_waste_quantity', 10, 2)->default(0)->change();
            $table->decimal('end_quantity', 10, 2)->default(0)->change();
        });

        Schema::table('inventory_item_movements', function (Blueprint $table) {
            $table->decimal('quantity', 8, 2)->change();
        });

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->decimal('quantity', 10, 2)->default(0)->change();
        });
    }
};
