<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equipment (معدات) rented with an order: shisha, gaming stations, catering
 * warmers. Flat price per use, no timer and no unit count. Each order type
 * lists the equipment it allows; order_equipment keeps a snapshot of name,
 * price and cost so later catalogue edits do not move old orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->decimal('usage_price', 12, 2)->default(0);
            $table->decimal('usage_cost', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('equipment_order_type', function (Blueprint $table) {
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->foreignId('order_type_id')->constrained('order_types')->cascadeOnDelete();
            $table->primary(['equipment_id', 'order_type_id']);
        });

        Schema::create('order_equipment', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->nullable()->constrained('equipment')->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->decimal('cost_total', 12, 2)->default(0);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['order_id', 'equipment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_equipment');
        Schema::dropIfExists('equipment_order_type');
        Schema::dropIfExists('equipment');
    }
};
