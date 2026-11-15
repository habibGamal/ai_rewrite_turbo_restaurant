<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The vendor change log reuses feature_flag_audits for module switches
 * (boolean) and the business type (text).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feature_flag_audits', function (Blueprint $table): void {
            $table->boolean('old_value')->nullable()->change();
            $table->boolean('new_value')->nullable()->change();
            $table->string('old_text')->nullable()->after('new_value');
            $table->string('new_text')->nullable()->after('old_text');
        });
    }

    public function down(): void
    {
        Schema::table('feature_flag_audits', function (Blueprint $table): void {
            $table->dropColumn(['old_text', 'new_text']);
        });
    }
};
