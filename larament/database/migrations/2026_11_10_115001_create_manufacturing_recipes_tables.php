<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturing_recipes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('manufacturing_recipe_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained('manufacturing_recipes')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->timestamps();
        });

        Schema::create('manufacturing_recipe_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained('manufacturing_recipes')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->boolean('is_main')->default(false);
            $table->decimal('expected_quantity', 12, 3);
            $table->string('cost_method')->default('percent')->nullable();
            $table->decimal('cost_value', 12, 4)->default(0)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manufacturing_recipe_outputs');
        Schema::dropIfExists('manufacturing_recipe_inputs');
        Schema::dropIfExists('manufacturing_recipes');
    }
};
