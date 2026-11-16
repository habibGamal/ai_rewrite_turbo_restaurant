<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One cashier handing an open drawer to another. The session carries on.
 *
 * @property int $id
 * @property int $terminal_session_id
 * @property int|null $from_user_id
 * @property int $to_user_id
 * @property string|null $note
 * @property Carbon $handed_at
 */
class TerminalSessionHandover extends Model
{
    protected $fillable = [
        'terminal_session_id',
        'from_user_id',
        'to_user_id',
        'note',
        'handed_at',
    ];

    protected $casts = [
        'handed_at' => 'datetime',
    ];

    /** @return BelongsTo<TerminalSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TerminalSession::class, 'terminal_session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
