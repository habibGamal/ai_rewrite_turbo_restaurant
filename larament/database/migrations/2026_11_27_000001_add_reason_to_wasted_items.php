<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional reason per wasted line (App\Enums\WasteReason), so the
 * inventory movements report can split waste by why it happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wasted_items', function (Blueprint $table) {
            $table->string('reason', 32)->nullable()->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('wasted_items', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
