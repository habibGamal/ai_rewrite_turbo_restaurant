<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared foundation for revenues, custom fees, equipment and guest count.
 *
 * audit_logs is an append-only record of sensitive edits (closed-shift money,
 * removed fee or equipment lines, ...). The order charge columns let fees and
 * equipment be built in parallel without both changing the totals formula.
 * Existing orders get 0, so old report numbers do not move.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->date('business_date')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 20);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->string('context', 50)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['shift_id', 'created_at']);
            $table->index('business_date');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('fees_total', 12, 2)->default(0);
            $table->decimal('equipment_total', 12, 2)->default(0);
            $table->decimal('equipment_cost', 12, 2)->default(0);
            $table->unsignedSmallInteger('guests_count')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['fees_total', 'equipment_total', 'equipment_cost', 'guests_count']);
        });

        Schema::dropIfExists('audit_logs');
    }
};
