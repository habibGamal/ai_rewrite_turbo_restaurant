<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Editing a closed shift's expenses and revenues became a vendor-owned
 * feature (support panel). A new install starts with it off. An install that
 * already edited a closed shift keeps it on, so an upgrade never takes a
 * working screen away from the manager.
 */
return new class extends Migration
{
    private const KEY = 'feature_closed_shift_edits_enabled';

    public function up(): void
    {
        if (! Schema::hasTable('settings') || DB::table('settings')->where('key', self::KEY)->exists()) {
            return;
        }

        $used = Schema::hasTable('shifts')
            && Schema::hasColumn('shifts', 'money_edited_at')
            && DB::table('shifts')->whereNotNull('money_edited_at')->exists();

        DB::table('settings')->insert([
            'key' => self::KEY,
            'value' => $used ? 'true' : 'false',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Cache::forget('settings.'.self::KEY);
        Cache::forget('settings.all');
    }

    public function down(): void
    {
        if (Schema::hasTable('settings')) {
            DB::table('settings')->where('key', self::KEY)->delete();
        }

        Cache::forget('settings.'.self::KEY);
        Cache::forget('settings.all');
    }
};
