<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Misc shift revenues (feature REVENUES): money the shop takes in a shift
 * that is not an order, such as a hall rental or a sold box. A revenue has
 * no items, no customer and no stock. Cash revenues add to the shift's
 * expected cash; card revenues are reported next to card payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revenue_types', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('revenues', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('revenue_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method', 10)->default('cash');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['shift_id', 'method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenues');
        Schema::dropIfExists('revenue_types');
    }
};
