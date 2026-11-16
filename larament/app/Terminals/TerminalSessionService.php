<?php

namespace App\Terminals;

use App\Enums\PaymentMethod;
use App\Models\Shift;
use App\Models\Terminal;
use App\Models\TerminalSession;
use App\Models\TerminalSessionHandover;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Support\Facades\DB;

/**
 * Opens and closes drawer sessions and works out what should be in a drawer.
 *
 * Expected cash of a session uses the same formula as the shift's
 * «النقدية المتاحة», filtered to the rows that session took:
 * opening cash + cash payments + external cash collections + cash revenues
 * − seller settlements − expenses − cash refunds.
 * The shift's figure is the sum of its sessions.
 */
class TerminalSessionService
{
    public function __construct(private readonly TerminalSessionResolver $resolver) {}

    /**
     * The session a shift gets on the hidden default drawer: opened with the
     * shift's opening cash by whoever started the shift.
     */
    public function openDefault(Shift $shift, ?User $user = null): TerminalSession
    {
        return $this->openOn($shift, Terminal::default(), $user, (float) ($shift->start_cash ?? 0));
    }

    public function openOn(Shift $shift, Terminal $terminal, ?User $user, float $openingCash): TerminalSession
    {
        $userId = $user?->getKey() ?? $shift->user_id;
        $closed = (bool) $shift->closed || $shift->end_at !== null;

        $session = TerminalSession::query()->create([
            'shift_id' => $shift->getKey(),
            'terminal_id' => $terminal->getKey(),
            'user_id' => $userId,
            'opened_by' => $userId,
            'opening_cash' => $openingCash,
            'opened_at' => $closed ? ($shift->start_at ?? now()) : now(),
            // A shift that is already closed (made before drawers existed)
            // gets a closed session carrying its recorded expected cash.
            'closed_at' => $closed ? ($shift->end_at ?? now()) : null,
            'closed_by' => $closed ? $userId : null,
            'expected_cash' => $closed ? $shift->end_cash : null,
        ]);

        $this->resolver->flush();

        return $session;
    }

    /**
     * Open a drawer for a cashier. The first drawer of the day opens the
     * store shift; later drawers join it and add their opening cash to the
     * shift's opening cash, so the shift stays the sum of its drawers.
     *
     * @throws TerminalException
     */
    public function openDrawer(Terminal $terminal, User $user, float $openingCash): TerminalSession
    {
        return DB::transaction(function () use ($terminal, $user, $openingCash): TerminalSession {
            $shifts = app(ShiftService::class);
            $shift = $shifts->getCurrentShift();

            if ($shift === null) {
                $shift = $shifts->startShift($openingCash, $user, $terminal);

                return $shift->terminalSessions()->where('terminal_id', $terminal->getKey())->open()->firstOrFail();
            }

            $shift = Shift::query()->lockForUpdate()->findOrFail($shift->getKey());

            if (! $terminal->is_active) {
                throw new TerminalException('الدرج ده موقوف.');
            }

            if ($this->openSessionOn($shift, $terminal) !== null) {
                throw new TerminalException('الدرج ده مفتوح بالفعل.');
            }

            $elsewhere = $this->openSessionOf($shift, $user);
            if ($elsewhere !== null) {
                throw new TerminalException('أنت فاتح درج تاني ('.$elsewhere->terminal->name.'). اقفله الأول.');
            }

            $session = $this->openOn($shift, $terminal, $user, $openingCash);

            $shift->forceFill(['start_cash' => round((float) $shift->start_cash + $openingCash, 2)])->save();

            return $session;
        });
    }

    /**
     * Another cashier takes over an open drawer. The session carries on;
     * only the signed-in cashier changes and the handover is recorded.
     *
     * @throws TerminalException
     */
    public function handover(TerminalSession $session, User $to, ?string $note = null): TerminalSessionHandover
    {
        return DB::transaction(function () use ($session, $to, $note): TerminalSessionHandover {
            $session = TerminalSession::query()->lockForUpdate()->with('terminal')->findOrFail($session->getKey());

            if (! $session->isOpen()) {
                throw new TerminalException('الدرج ده اتقفل.');
            }

            $elsewhere = TerminalSession::query()
                ->where('shift_id', $session->shift_id)
                ->where('user_id', $to->getKey())
                ->whereKeyNot($session->getKey())
                ->open()
                ->with('terminal')
                ->first();
            if ($elsewhere !== null) {
                throw new TerminalException('أنت فاتح درج تاني ('.$elsewhere->terminal->name.'). اقفله الأول.');
            }

            $handover = $session->handovers()->create([
                'from_user_id' => $session->user_id,
                'to_user_id' => $to->getKey(),
                'note' => $note,
                'handed_at' => now(),
            ]);

            $session->forceFill(['user_id' => $to->getKey()])->save();
            $this->resolver->flush();

            return $handover;
        });
    }

    public function openSessionOn(Shift $shift, Terminal $terminal): ?TerminalSession
    {
        return TerminalSession::query()
            ->where('shift_id', $shift->getKey())
            ->where('terminal_id', $terminal->getKey())
            ->open()
            ->first();
    }

    public function openSessionOf(Shift $shift, User $user): ?TerminalSession
    {
        return TerminalSession::query()
            ->where('shift_id', $shift->getKey())
            ->where('user_id', $user->getKey())
            ->open()
            ->with('terminal')
            ->first();
    }

    /**
     * Close every open session of a shift, recording its expected cash.
     */
    public function closeAllOpen(Shift $shift, ?User $by = null): void
    {
        TerminalSession::query()
            ->where('shift_id', $shift->getKey())
            ->open()
            ->get()
            ->each(fn (TerminalSession $session) => $this->close($session, $by));
    }

    public function close(TerminalSession $session, ?User $by = null, ?string $note = null): TerminalSession
    {
        $session->forceFill([
            'expected_cash' => $this->expectedCash($session),
            'closed_at' => now(),
            'closed_by' => $by?->getKey() ?? auth()->id() ?? $session->user_id,
            'close_note' => $note,
        ])->save();

        $this->resolver->flush();

        return $session;
    }

    /**
     * Refresh the recorded expected cash of a closed shift's closed sessions
     * (after its money was edited from «مصروفات وإيرادات الورديات»).
     */
    public function recomputeClosed(Shift $shift): void
    {
        TerminalSession::query()
            ->where('shift_id', $shift->getKey())
            ->whereNotNull('closed_at')
            ->get()
            ->each(fn (TerminalSession $session) => $session->forceFill([
                'expected_cash' => $this->expectedCash($session),
            ])->save());
    }

    public function expectedCash(TerminalSession $session): float
    {
        $id = $session->getKey();

        $cashPayments = (float) DB::table('payments')
            ->where('terminal_session_id', $id)
            ->where('method', PaymentMethod::CASH->value)
            ->sum('amount');

        $externalCash = (float) DB::table('external_order_collections')
            ->where('terminal_session_id', $id)
            ->where('method', 'cash')
            ->sum('amount');

        $cashRevenues = (float) DB::table('revenues')
            ->where('terminal_session_id', $id)
            ->where('method', 'cash')
            ->sum('amount');

        $settlements = (float) DB::table('external_seller_settlements')
            ->where('terminal_session_id', $id)
            ->sum('amount');

        $expenses = (float) DB::table('expenses')
            ->where('terminal_session_id', $id)
            ->sum('amount');

        $cashRefunds = (float) DB::table('refunds')
            ->whereIn('order_return_id', DB::table('order_returns')->where('terminal_session_id', $id)->select('id'))
            ->where('method', 'cash')
            ->sum('amount');

        return round(
            (float) $session->opening_cash + $cashPayments + $externalCash + $cashRevenues - $settlements - $expenses - $cashRefunds,
            2,
        );
    }
}
