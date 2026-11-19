<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per tool call an AI client made over MCP: who, which client, which
 * tool with what arguments, whether it worked and how long it took.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('client_id', 100)->nullable();
            $table->string('client_name')->nullable();
            $table->string('tool', 100);
            $table->json('arguments')->nullable();
            $table->boolean('ok')->default(true);
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['user_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tool_calls');
    }
};
