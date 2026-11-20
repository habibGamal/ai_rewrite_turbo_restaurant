<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A returned line is now one of three kinds: an order item (by quantity), a
 * custom fee (by amount) or an equipment line (by quantity). Exactly one of
 * order_item_id / order_fee_id / order_equipment_id is set; OrderReturnItem
 * enforces it on save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_return_items', function (Blueprint $table) {
            $table->unsignedBigInteger('order_item_id')->nullable()->change();
            $table->foreignId('order_fee_id')->nullable()->after('order_item_id')->constrained('order_fees')->cascadeOnDelete();
            $table->foreignId('order_equipment_id')->nullable()->after('order_fee_id')->constrained('order_equipment')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_return_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_equipment_id');
            $table->dropConstrainedForeignId('order_fee_id');
        });
    }
};
