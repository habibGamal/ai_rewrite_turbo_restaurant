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
        Schema::create('kitchen_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status')->default('active')->index();
            $table->boolean('is_rush')->default(false);
            $table->timestamp('first_sent_at')->useCurrent();
            $table->timestamp('done_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('cancel_acknowledged_at')->nullable();
            $table->boolean('auto_finished')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kitchen_tickets');
    }
};
