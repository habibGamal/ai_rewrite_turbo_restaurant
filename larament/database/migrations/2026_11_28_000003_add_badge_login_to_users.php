<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Badge (barcode card) quick login to the POS. Only a hash of the card code
 * is kept; printing a new card replaces it. Managers also type a 4-digit PIN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('badge_hash', 64)->nullable()->unique();
            $table->timestamp('badge_issued_at')->nullable();
            $table->string('badge_pin')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['badge_hash']);
            $table->dropColumn(['badge_hash', 'badge_issued_at', 'badge_pin']);
        });
    }
};
