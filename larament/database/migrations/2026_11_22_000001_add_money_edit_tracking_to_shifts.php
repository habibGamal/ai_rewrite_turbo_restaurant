<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks shifts whose expenses or revenues were changed after they closed
 * (from «مصروفات وإيرادات الورديات»), so reports can flag them «معدلة».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->timestamp('money_edited_at')->nullable();
            $table->unsignedInteger('money_edited_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['money_edited_at', 'money_edited_count']);
        });
    }
};
