<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FEATURE_KEYS = [
        'feature_revenues_enabled',
        'feature_custom_fees_enabled',
        'feature_outsourced_orders_enabled',
        'feature_equipment_enabled',
    ];

    private const OUTSOURCED_TABLES = [
        'external_sellers',
        'external_products',
        'external_orders',
        'external_order_items',
        'external_order_collections',
        'external_seller_settlements',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('feature_flag_audits')) {
            Schema::create('feature_flag_audits', function (Blueprint $table): void {
                $table->id();
                $table->string('feature_key')->index();
                $table->boolean('old_value');
                $table->boolean('new_value');
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('changed_at')->useCurrent();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('settings')) {
            return;
        }

        $now = now();
        $hasOutsourcedData = $this->hasExistingOutsourcedData();

        $defaults = [
            'feature_revenues_enabled' => 'false',
            'feature_custom_fees_enabled' => 'false',
            'feature_outsourced_orders_enabled' => $hasOutsourcedData ? 'true' : 'false',
            'feature_equipment_enabled' => 'false',
        ];

        foreach ($defaults as $key => $defaultValue) {
            $existing = DB::table('settings')->where('key', $key)->first();

            if ($existing === null) {
                DB::table('settings')->insert([
                    'key' => $key,
                    'value' => $defaultValue,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $normalized = $this->normalizeExistingValue($key, $existing->value, $defaultValue, $hasOutsourcedData);
                DB::table('settings')
                    ->where('key', $key)
                    ->update([
                        'value' => $normalized,
                        'updated_at' => $now,
                    ]);
            }

            Cache::forget("settings.{$key}");
        }

        Cache::forget('settings.all');
    }

    public function down(): void
    {
        if (Schema::hasTable('settings')) {
            DB::table('settings')
                ->whereIn('key', self::FEATURE_KEYS)
                ->delete();
        }

        foreach (self::FEATURE_KEYS as $key) {
            Cache::forget("settings.{$key}");
        }
        Cache::forget('settings.all');

        Schema::dropIfExists('feature_flag_audits');
    }

    private function hasExistingOutsourcedData(): bool
    {
        foreach (self::OUTSOURCED_TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function normalizeExistingValue(
        string $key,
        mixed $currentValue,
        string $defaultValue,
        bool $hasOutsourcedData
    ): string {
        if ($key === 'feature_outsourced_orders_enabled' && $hasOutsourcedData) {
            return 'true';
        }

        if ($currentValue === true || $currentValue === 1 || $currentValue === '1' || $currentValue === 'true') {
            return 'true';
        }

        if ($currentValue === false || $currentValue === 0 || $currentValue === '0' || $currentValue === 'false') {
            return 'false';
        }

        return $defaultValue;
    }
};
