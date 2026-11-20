<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devices bound to a drawer (the browser keeps a random token in a cookie,
 * the server keeps its hash), and cashier handovers on an open drawer: the
 * drawer session carries on and the handover is only recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminal_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terminal_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('label')->nullable();
            $table->foreignId('bound_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('terminal_session_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terminal_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->constrained('users');
            $table->string('note', 500)->nullable();
            $table->timestamp('handed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_session_handovers');
        Schema::dropIfExists('terminal_devices');
    }
};
