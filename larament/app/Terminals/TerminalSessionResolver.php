<?php

namespace App\Terminals;

use App\Models\Shift;
use App\Models\Terminal;
use App\Models\TerminalSession;

/**
 * Which drawer session a new money row belongs to.
 *
 * Scoped per request. When the request comes from a device bound to a drawer
 * (multiple terminals on), the POS sets that drawer's session as current and
 * every row of that shift goes to it. Otherwise (feature off, admin panel,
 * jobs, web orders) the row goes to the shift's open session on the default
 * drawer, then any open session, then the shift's last session; a shift with
 * no session at all gets its default session opened on demand.
 */
class TerminalSessionResolver
{
    private ?TerminalSession $current = null;

    private ?Terminal $terminal = null;

    /** @var array<int, TerminalSession> */
    private array $byShift = [];

    public function setCurrent(?TerminalSession $session): void
    {
        $this->current = $session;
    }

    public function current(): ?TerminalSession
    {
        return $this->current;
    }

    /**
     * The drawer this device is bound to (multiple terminals on), open or not.
     */
    public function setTerminal(?Terminal $terminal): void
    {
        $this->terminal = $terminal;
    }

    public function terminal(): ?Terminal
    {
        return $this->terminal ?? $this->current?->terminal;
    }

    public function forShift(int $shiftId): ?TerminalSession
    {
        if ($this->current !== null && $this->current->shift_id === $shiftId) {
            return $this->current;
        }

        if (isset($this->byShift[$shiftId]) && $this->byShift[$shiftId]->exists) {
            return $this->byShift[$shiftId];
        }

        $session = $this->fallback($shiftId);

        if ($session !== null) {
            $this->byShift[$shiftId] = $session;
        }

        return $session;
    }

    /**
     * Forget cached sessions (after a session opens or closes).
     */
    public function flush(): void
    {
        $this->byShift = [];
    }

    private function fallback(int $shiftId): ?TerminalSession
    {
        $sessions = TerminalSession::query()
            ->where('shift_id', $shiftId)
            ->with('terminal')
            ->orderBy('id')
            ->get();

        if ($sessions->isEmpty()) {
            $shift = Shift::query()->find($shiftId);

            return $shift === null ? null : app(TerminalSessionService::class)->openDefault($shift);
        }

        return $sessions->first(fn (TerminalSession $s) => $s->isOpen() && $s->terminal?->is_default)
            ?? $sessions->first(fn (TerminalSession $s) => $s->isOpen())
            ?? $sessions->last();
    }
}
