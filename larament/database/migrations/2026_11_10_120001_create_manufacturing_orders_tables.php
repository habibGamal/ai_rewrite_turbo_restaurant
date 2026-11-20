<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturing_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturing_recipe_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('destination_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('planned_main_quantity', 12, 3);
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('closed_at')->nullable()->index();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('business_date')->nullable()->index();
            $table->decimal('total_input_cost', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('manufacturing_order_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturing_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('planned_quantity', 12, 3)->default(0);
            $table->decimal('actual_quantity', 12, 3);
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('manufacturing_order_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturing_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->boolean('is_main')->default(false);
            $table->string('cost_method');
            $table->decimal('cost_value', 12, 4)->default(0);
            $table->decimal('expected_quantity', 12, 3);
            $table->decimal('actual_quantity', 12, 3)->nullable();
            $table->decimal('allocated_cost', 14, 2)->default(0);
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->decimal('variance_quantity', 12, 3)->default(0);
            $table->decimal('variance_value', 14, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manufacturing_order_outputs');
        Schema::dropIfExists('manufacturing_order_inputs');
        Schema::dropIfExists('manufacturing_orders');
    }
};
