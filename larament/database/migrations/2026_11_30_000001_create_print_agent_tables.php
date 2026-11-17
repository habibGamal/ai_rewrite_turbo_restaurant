<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receipt printing through the Turbo Label app on the drawer's own computer.
 *
 * When the POS is hosted far from the shop (a VPS), the server can't reach a
 * USB or LAN printer at the counter. A drawer set to print via the agent
 * queues its ESC/POS bytes here; the Turbo Label app on that drawer's
 * computer, paired with a token, polls the queue and prints them raw.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terminals', function (Blueprint $table) {
            // server = IP / share from this server; agent = Turbo Label app.
            $table->string('print_via', 20)->default('server');
            // Windows printer name on the drawer's computer; empty = the app's choice.
            $table->string('agent_printer')->nullable();
            $table->string('agent_token_hash', 64)->nullable()->index();
            $table->timestamp('agent_paired_at')->nullable();
            $table->timestamp('agent_last_seen_at')->nullable();
            // What the app reports: version, computer name, printers.
            $table->json('agent_info')->nullable();
        });

        Schema::create('print_agent_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terminal_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('printer')->nullable();
            // Base64 of the raw ESC/POS bytes.
            $table->longText('payload');
            $table->string('status', 20)->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['terminal_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_agent_jobs');

        Schema::table('terminals', function (Blueprint $table) {
            $table->dropIndex(['agent_token_hash']);
            $table->dropColumn(['print_via', 'agent_printer', 'agent_token_hash', 'agent_paired_at', 'agent_last_seen_at', 'agent_info']);
        });
    }
};
