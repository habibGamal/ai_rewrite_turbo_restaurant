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
        // MariaDB / MySQL implicitly adds ON UPDATE CURRENT_TIMESTAMP to the first
        // non-null TIMESTAMP column in a table when created without an explicit default.
        // Specifying ->useCurrent() ensures the column retains DEFAULT CURRENT_TIMESTAMP
        // without the automatic ON UPDATE trigger, preserving initial sent/start timestamps for reports.
        Schema::table('kitchen_tickets', function (Blueprint $table): void {
            $table->timestamp('first_sent_at')->useCurrent()->change();
        });

        Schema::table('kitchen_ticket_rounds', function (Blueprint $table): void {
            $table->timestamp('started_at')->useCurrent()->change();
        });

        Schema::table('kitchen_ticket_items', function (Blueprint $table): void {
            $table->timestamp('sent_at')->useCurrent()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Preserving original timestamps without automatic updates is intentional.
    }
};
