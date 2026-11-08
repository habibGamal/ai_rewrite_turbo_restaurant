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
        Schema::create('kitchen_ticket_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kitchen_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kitchen_ticket_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->foreignId('kitchen_station_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 10, 3);
            $table->decimal('original_quantity', 10, 3);
            $table->text('notes')->nullable();
            $table->string('kind');
            $table->string('status')->default('pending')->index();
            $table->unsignedSmallInteger('target_minutes');
            $table->timestamp('sent_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('voided_after_done')->default(false);
            $table->boolean('needs_ack')->default(false);
            $table->boolean('auto_finished')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kitchen_ticket_items');
    }
};
