<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tool call an AI client made over MCP, written by App\Mcp\Support\ToolCallLog.
 * Kept for 180 days (pruned by the daily model:prune run).
 *
 * @property array<string, mixed>|null $arguments
 */
class McpToolCall extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    public const KEEP_DAYS = 180;

    protected $fillable = [
        'user_id',
        'client_id',
        'client_name',
        'tool',
        'arguments',
        'ok',
        'error',
        'duration_ms',
    ];

    protected $casts = [
        'arguments' => 'array',
        'ok' => 'boolean',
        'duration_ms' => 'integer',
        'created_at' => 'datetime',
    ];

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
