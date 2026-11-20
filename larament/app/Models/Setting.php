<?php

namespace App\Models;

use App\Enums\SettingKey;
use App\Services\FeatureService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
    ];

    protected $casts = [
        'value' => 'string',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $setting): void {
            self::invalidateCache($setting->key);
        });

        static::deleted(function (self $setting): void {
            self::invalidateCache($setting->key);
        });
    }

    public static function invalidateCache(?string $key = null): void
    {
        $forget = static function () use ($key): void {
            if ($key !== null && $key !== '') {
                Cache::forget("settings.{$key}");
            }
            Cache::forget('settings.all');
        };

        $forget();

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($forget);
        }
    }

    /**
     * Get setting value by key (static method for convenience)
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();

        return $setting !== null ? $setting->value : $default;
    }

    /**
     * Set setting value by key (static method for convenience)
     */
    public static function setValue(string $key, mixed $value): void
    {
        $settingKey = SettingKey::tryFrom($key);

        if ($settingKey?->isFeatureFlag()) {
            app(FeatureService::class)->updateFeatures([$key => $value]);

            return;
        }

        if ($settingKey?->isBooleanSetting()) {
            $value = SettingKey::parseStoredBoolean($key, $value) ? 'true' : 'false';
        } elseif (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }

        static::updateOrCreate(
            ['key' => $key],
            ['value' => (string) $value]
        );
    }

    /**
     * Check if a setting exists
     */
    public static function hasKey(string $key): bool
    {
        return static::where('key', $key)->exists();
    }

    /**
     * Get all settings as key-value array
     */
    public static function getAllAsArray(): array
    {
        return static::pluck('value', 'key')->toArray();
    }
}
