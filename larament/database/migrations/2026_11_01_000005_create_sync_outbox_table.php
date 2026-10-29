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
        Schema::create('sync_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('doc_type', 32);
            $table->string('doc_uuid', 36);
            $table->string('doc_key', 32)->nullable();
            $table->string('op', 8)->default('upsert');
            $table->tinyInteger('priority')->default(5);
            $table->timestamp('occurred_at')->useCurrent();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();

            $table->index(['sent_at', 'failed_at', 'priority', 'id']);
            $table->index(['doc_type', 'doc_uuid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_outbox');
    }
};
