<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kitchen printing through the Turbo Label app on a shop computer.
 *
 * Mirrors the print-agent columns on terminals so a kitchen printer can
 * either be reached directly by the server (IP / Windows share) or queued
 * here for a paired Turbo Label app to poll and print raw ESC/POS bytes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->string('print_via', 20)->default('server');
            $table->string('agent_printer')->nullable();
            $table->string('agent_token_hash', 64)->nullable()->index();
            $table->timestamp('agent_paired_at')->nullable();
            $table->timestamp('agent_last_seen_at')->nullable();
            $table->json('agent_info')->nullable();
        });

        Schema::table('print_agent_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('terminal_id')->nullable()->change();
            $table->foreignId('printer_id')->nullable()->after('terminal_id')->constrained('printers')->cascadeOnDelete();
            $table->index(['printer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('print_agent_jobs', function (Blueprint $table) {
            $table->dropIndex(['printer_id', 'status']);
            $table->dropConstrainedForeignId('printer_id');
        });

        Schema::table('printers', function (Blueprint $table) {
            $table->dropIndex(['agent_token_hash']);
            $table->dropColumn([
                'print_via',
                'agent_printer',
                'agent_token_hash',
                'agent_paired_at',
                'agent_last_seen_at',
                'agent_info',
            ]);
        });
    }
};
