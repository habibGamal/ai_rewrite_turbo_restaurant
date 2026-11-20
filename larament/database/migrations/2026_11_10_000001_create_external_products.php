<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_seller_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('price', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['external_seller_id', 'name']);
        });
        Schema::table('external_order_items', function (Blueprint $table) {
            $table->foreignId('external_product_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('external_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('external_product_id');
        });
        Schema::dropIfExists('external_products');
    }
};
