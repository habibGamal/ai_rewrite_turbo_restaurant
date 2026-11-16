<?php

namespace App\Models;

use App\Models\Concerns\HasSyncUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One drawer open inside one shift. Money taken or paid out at the POS points
 * here through terminal_session_id. A handover between cashiers keeps the
 * same session and only changes user_id.
 *
 * @property int $id
 * @property string $uuid
 * @property int $shift_id
 * @property int $terminal_id
 * @property int|null $user_id
 * @property int|null $opened_by
 * @property int|null $closed_by
 * @property string $opening_cash
 * @property string|null $expected_cash
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property string|null $close_note
 */
class TerminalSession extends Model
{
    use HasSyncUuid;

    protected $fillable = [
        'uuid',
        'shift_id',
        'terminal_id',
        'user_id',
        'opened_by',
        'closed_by',
        'opening_cash',
        'expected_cash',
        'opened_at',
        'closed_at',
        'close_note',
    ];

    protected $casts = [
        'opening_cash' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('closed_at');
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<Terminal, $this> */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
