<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dine_tables', function (Blueprint $table): void {
            $table->string('order_type_code')->default('dine_in');
            $table->dropUnique(['table_number']);
            $table->unique(['order_type_code', 'table_number']);
        });
    }

    public function down(): void
    {
        Schema::table('dine_tables', function (Blueprint $table): void {
            $table->dropUnique(['order_type_code', 'table_number']);
            $table->unique('table_number');
            $table->dropColumn('order_type_code');
        });
    }
};
