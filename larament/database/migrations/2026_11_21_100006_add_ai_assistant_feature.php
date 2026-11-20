<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * «المساعد الذكي»: AI clients (ChatGPT, Claude, Gemini) read the shop's data
 * over MCP. A vendor feature that starts off, plus the permission a user
 * needs to connect a client; admins get it.
 */
return new class extends Migration
{
    private const KEY = 'feature_ai_assistant_enabled';

    private const PERMISSION = 'use_ai_assistant';

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

        if (Schema::hasTable('permissions')) {
            $permission = Permission::firstOrCreate(['name' => self::PERMISSION, 'guard_name' => 'web']);
            Role::query()->where('name', 'admin')->first()?->givePermissionTo($permission);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('settings')) {
            DB::table('settings')->where('key', self::KEY)->delete();
            Cache::forget('settings.'.self::KEY);
            Cache::forget('settings.all');
        }

        if (Schema::hasTable('permissions')) {
            Permission::query()->where('name', self::PERMISSION)->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
