<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformConnection extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'platform_connections';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'url',
        'branch_uuid',
        'token',
        'branch_secret',
        'timezone',
        'status',
        'paired_at',
        'last_success_at',
        'last_heartbeat_at',
        'last_error',
        'backfill_status',
        'backfill_catalog_queued',
        'backfill_range_queued',
        'backfill_range_from',
        'backfill_range_to',
        'backfill_attempts',
        'backfill_next_attempt_at',
        'backfill_completed_at',
        'backfill_lock_owner',
        'backfill_locked_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token',
        'branch_secret',
    ];

    /**
     * Cached active connection instance for the current request.
     */
    protected static ?self $activeCache = null;

    /**
     * Whether the active connection has been resolved in the current request.
     */
    protected static bool $hasResolvedActive = false;

    /**
     * Application instance that activeCache was resolved in.
     */
    protected static ?\Illuminate\Contracts\Foundation\Application $resolvedApp = null;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function booted(): void
    {
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'branch_secret' => 'encrypted',
            'paired_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'backfill_catalog_queued' => 'boolean',
            'backfill_range_queued' => 'boolean',
            'backfill_range_from' => 'date',
            'backfill_range_to' => 'date',
            'backfill_attempts' => 'integer',
            'backfill_next_attempt_at' => 'datetime',
            'backfill_completed_at' => 'datetime',
            'backfill_locked_at' => 'datetime',
        ];
    }

    /**
     * Get the active platform connection, cached per request.
     */
    public static function active(): ?self
    {
        if (static::$resolvedApp !== app()) {
            static::flushCache();
            static::$resolvedApp = app();
        }

        if (! static::$hasResolvedActive) {
            static::$activeCache = static::where('status', 'active')->first();
            static::$hasResolvedActive = true;
        }

        return static::$activeCache;
    }

    /**
     * Flush the cached active connection.
     */
    public static function flushCache(): void
    {
        static::$activeCache = null;
        static::$hasResolvedActive = false;
        static::$resolvedApp = null;
    }
}
