<?php

use App\Mcp\Support\AiViews;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «الاستعلامات المباشرة للمساعد الذكي»: a vendor feature (off by default) that
 * lets the shop admin's AI client run SELECT queries on curated ai_* views.
 */
return new class extends Migration
{
    private const KEY = 'feature_ai_queries_enabled';

    public function up(): void
    {
        if (Schema::hasTable('settings') && ! DB::table('settings')->where('key', self::KEY)->exists()) {
            DB::table('settings')->insert([
                'key' => self::KEY,
                'value' => 'false',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Cache::forget('settings.'.self::KEY);
            Cache::forget('settings.all');
        }

        // SQLite creates them on first use (AiViews::ensure): it would refuse
        // the table rebuilds later migrations do while a view reads the table.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            AiViews::install();
        }
    }

    public function down(): void
    {
        AiViews::drop();

        if (Schema::hasTable('settings')) {
            DB::table('settings')->where('key', self::KEY)->delete();
            Cache::forget('settings.'.self::KEY);
            Cache::forget('settings.all');
        }
    }
};
