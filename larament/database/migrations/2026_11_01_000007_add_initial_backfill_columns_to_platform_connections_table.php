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
        Schema::table('platform_connections', function (Blueprint $table) {
            $table->string('backfill_status', 16)->default('completed');
            $table->boolean('backfill_catalog_queued')->default(false);
            $table->boolean('backfill_range_queued')->default(false);
            $table->date('backfill_range_from')->nullable();
            $table->date('backfill_range_to')->nullable();
            $table->smallInteger('backfill_attempts')->default(0);
            $table->timestamp('backfill_next_attempt_at')->nullable();
            $table->timestamp('backfill_completed_at')->nullable();
            $table->string('backfill_lock_owner', 64)->nullable();
            $table->timestamp('backfill_locked_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_connections', function (Blueprint $table) {
            $table->dropColumn([
                'backfill_status',
                'backfill_catalog_queued',
                'backfill_range_queued',
                'backfill_range_from',
                'backfill_range_to',
                'backfill_attempts',
                'backfill_next_attempt_at',
                'backfill_completed_at',
                'backfill_lock_owner',
                'backfill_locked_at',
            ]);
        });
    }
};
