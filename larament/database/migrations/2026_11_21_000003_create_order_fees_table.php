<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom fee lines on an order («رسوم إضافية»): free-text name and amount,
 * typed by the cashier. orders.fees_total holds their sum.
 *
 * name_key is the name trimmed, with spaces collapsed and lower-cased, so
 * the same fee typed twice groups together in reports and suggestions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_fees', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('name_key', 100)->index();
            $table->decimal('amount', 12, 2);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_fees');
    }
};
