<?php

namespace App\Models\Concerns;

use App\Models\TerminalSession;
use App\Terminals\TerminalSessionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A money row taken or paid out by a drawer. On create, a row that names a
 * shift but no drawer session gets the session the resolver picks for that
 * shift (the drawer of this device, else the shift's open default drawer).
 *
 * @mixin Model
 */
trait BelongsToTerminalSession
{
    public static function bootBelongsToTerminalSession(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute('terminal_session_id') !== null) {
                return;
            }

            $shiftId = $model->getAttribute('shift_id');

            if ($shiftId === null) {
                return;
            }

            $model->setAttribute(
                'terminal_session_id',
                app(TerminalSessionResolver::class)->forShift((int) $shiftId)?->getKey(),
            );
        });
    }

    public function initializeBelongsToTerminalSession(): void
    {
        if ($this->getFillable() !== []) {
            $this->mergeFillable(['terminal_session_id']);
        }
    }

    /** @return BelongsTo<TerminalSession, $this> */
    public function terminalSession(): BelongsTo
    {
        return $this->belongsTo(TerminalSession::class);
    }
}
