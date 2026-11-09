<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_sellers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('external_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_order_id')->constrained('orders');
            $table->foreignId('external_seller_id')->constrained('external_sellers');
            $table->string('seller_name');
            $table->string('status')->default('pending');
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['host_order_id', 'external_seller_id']);
        });
        Schema::create('external_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_order_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('quantity', 12, 3);
            $table->decimal('price', 12, 2);
            $table->decimal('total', 12, 2);
            $table->timestamps();
        });
        Schema::create('external_order_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_order_id')->constrained();
            $table->foreignId('shift_id')->constrained();
            $table->string('method');
            $table->decimal('amount', 12, 2);
            $table->foreignId('user_id')->constrained();
            $table->timestamps();
        });
        Schema::create('external_seller_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_seller_id')->constrained();
            $table->foreignId('shift_id')->constrained();
            $table->decimal('amount', 12, 2);
            $table->foreignId('user_id')->constrained();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_seller_settlements');
        Schema::dropIfExists('external_order_collections');
        Schema::dropIfExists('external_order_items');
        Schema::dropIfExists('external_orders');
        Schema::dropIfExists('external_sellers');
    }
};
