<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retail variants (Phase 6b). A variant is an ordinary products row (the SKU)
 * grouped under a product_groups row, so every stock, costing and order table
 * that keys on product_id keeps working without a variant column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('brand')->nullable();
            // Prefix for generated SKUs, e.g. TSH -> TSH-BLK-M.
            $table->string('sku_prefix', 32)->nullable();
            // Price every variant sells at unless it has its own override.
            $table->decimal('base_price', 10, 2)->default(0);
            // Starting cost for newly generated variants; purchases update each SKU's cost after that.
            $table->decimal('base_cost', 12, 4)->default(0);
            $table->string('unit')->default('piece');
            $table->string('image')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_options', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['product_group_id', 'name']);
        });

        Schema::create('product_option_values', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_option_id')->constrained()->cascadeOnDelete();
            $table->string('value');
            $table->string('code', 16)->nullable();
            $table->string('color_hex', 9)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['product_option_id', 'value']);
        });

        Schema::create('product_variant_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // A value that a SKU uses cannot be deleted; deactivate the SKU instead.
            $table->foreignId('product_option_value_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'product_option_value_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('product_group_id')->nullable()->after('category_id')
                ->constrained()->nullOnDelete();
            $table->string('sku', 64)->nullable()->unique()->after('product_ref');
            // Cached "أسود / M" so lists, receipts and reports need no joins.
            $table->string('variant_label')->nullable()->after('name');
            // true = this SKU keeps its own price when the group price changes.
            $table->boolean('has_custom_price')->default(false)->after('price');
            $table->boolean('is_active')->default(true)->after('legacy');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_group_id');
            $table->dropUnique(['sku']);
            $table->dropColumn(['sku', 'variant_label', 'has_custom_price', 'is_active']);
        });

        Schema::dropIfExists('product_variant_values');
        Schema::dropIfExists('product_option_values');
        Schema::dropIfExists('product_options');
        Schema::dropIfExists('product_groups');
    }
};
