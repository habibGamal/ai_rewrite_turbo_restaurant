<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deferred payment and sales returns became vendor-owned features (support
 * panel). A new install starts with both off. An install that already uses
 * one keeps it on, so an upgrade never takes a working screen away from the
 * cashier: deferred payment when orders were sold on a credit order type or
 * money is still owed, returns when any sales return was recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $this->seed('feature_deferred_payment_enabled', $this->usesDeferredPayment());
        $this->seed('feature_returns_enabled', Schema::hasTable('order_returns') && DB::table('order_returns')->exists());

        Cache::forget('settings.all');
    }

    public function down(): void
    {
        if (Schema::hasTable('settings')) {
            DB::table('settings')->whereIn('key', ['feature_deferred_payment_enabled', 'feature_returns_enabled'])->delete();
        }

        Cache::forget('settings.feature_deferred_payment_enabled');
        Cache::forget('settings.feature_returns_enabled');
        Cache::forget('settings.all');
    }

    private function seed(string $key, bool $enabled): void
    {
        if (DB::table('settings')->where('key', $key)->exists()) {
            return;
        }

        DB::table('settings')->insert([
            'key' => $key,
            'value' => $enabled ? 'true' : 'false',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Cache::forget("settings.{$key}");
    }

    /**
     * Credit columns are on for the seeded «شركات» type everywhere, so they
     * alone do not prove use: the install must have sold on a credit type or
     * still be owed money.
     */
    private function usesDeferredPayment(): bool
    {
        if (! Schema::hasTable('orders')) {
            return false;
        }

        $owed = DB::table('orders')
            ->where('status', 'completed')
            ->whereIn('payment_status', ['pending', 'partial_paid'])
            ->exists();

        if ($owed || ! Schema::hasTable('order_types')) {
            return $owed;
        }

        $creditTypes = DB::table('order_types')
            ->where(fn ($query) => $query->where('allow_partial_payment', true)->orWhere('allow_pay_later', true))
            ->pluck('code');

        return $creditTypes->isNotEmpty() && DB::table('orders')->whereIn('type', $creditTypes)->exists();
    }
};
