<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Installs already in use skip the first-run setup wizard: they count as
 * set up when they have any order or product. A fresh install starts with
 * the wizard pending for the vendor.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings') || DB::table('settings')->where('key', 'setup_completed')->exists()) {
            return;
        }

        $inUse = (Schema::hasTable('orders') && DB::table('orders')->exists())
            || (Schema::hasTable('products') && DB::table('products')->exists());

        DB::table('settings')->insert([
            'key' => 'setup_completed',
            'value' => $inUse ? 'true' : 'false',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Cache::forget('settings.setup_completed');
        Cache::forget('settings.all');
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'setup_completed')->delete();
    }
};
