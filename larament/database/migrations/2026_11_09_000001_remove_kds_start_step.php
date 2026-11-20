<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('kitchen_ticket_items')->where('status', 'started')->update(['status' => 'pending']);
        Schema::table('kitchen_ticket_items', function (Blueprint $table): void {
            $table->dropColumn('started_at');
        });
        DB::table('settings')->where('key', 'kds_require_start')->delete();
    }

    public function down(): void
    {
        Schema::table('kitchen_ticket_items', function (Blueprint $table): void {
            $table->timestamp('started_at')->nullable()->after('sent_at');
        });
        DB::table('settings')->updateOrInsert(['key' => 'kds_require_start'], ['value' => 'false']);
    }
};
