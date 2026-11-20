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
        if (Schema::hasTable('expence_types') && ! Schema::hasColumn('expence_types', 'category')) {
            Schema::table('expence_types', function (Blueprint $table) {
                $table->string('category')->default('other')->after('name');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('expence_types') && Schema::hasColumn('expence_types', 'category')) {
            Schema::table('expence_types', function (Blueprint $table) {
                $table->dropColumn('category');
            });
        }
    }
};
