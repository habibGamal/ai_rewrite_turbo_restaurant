<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Runs before UUID/business-date backfills on fresh installations. An
        // explicit default prevents MariaDB's implicit ON UPDATE timestamp rule.
        Schema::table('shifts', function (Blueprint $table): void {
            $table->timestamp('start_at')->useCurrent()->change();
        });
    }

    public function down(): void
    {
        // Keep the safe definition; restoring automatic overwrites loses data.
    }
};
