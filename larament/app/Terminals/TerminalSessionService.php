<?php

namespace App\Terminals;

use App\Enums\PaymentMethod;
use App\Models\Shift;
use App\Models\Terminal;
use App\Models\TerminalSession;
use App\Models\User;
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
        $userId = $user?->getKey() ?? $shift->user_id;

        $closed = (bool) $shift->closed || $shift->end_at !== null;

        $session = TerminalSession::query()->create([
            'shift_id' => $shift->getKey(),
            'terminal_id' => Terminal::default()->getKey(),
            'user_id' => $userId,
            'opened_by' => $userId,
            'opening_cash' => $shift->start_cash ?? 0,
            'opened_at' => $shift->start_at ?? now(),
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
