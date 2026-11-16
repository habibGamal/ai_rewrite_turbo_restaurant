<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A browser bound to a drawer. The browser holds the token in a cookie; only
 * its SHA-256 hash is stored.
 *
 * @property int $id
 * @property int $terminal_id
 * @property string $token_hash
 * @property string|null $label
 * @property int|null $bound_by
 * @property Carbon|null $last_seen_at
 */
class TerminalDevice extends Model
{
    protected $fillable = [
        'terminal_id',
        'token_hash',
        'label',
        'bound_by',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    /** @return BelongsTo<Terminal, $this> */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /** @return BelongsTo<User, $this> */
    public function boundBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bound_by');
    }
}
