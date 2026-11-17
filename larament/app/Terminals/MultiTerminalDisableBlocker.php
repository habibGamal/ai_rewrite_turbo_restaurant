<?php

namespace App\Terminals;

use App\Models\Shift;
use App\Models\TerminalSession;
use Illuminate\Support\Facades\Schema;

/**
 * Refuses to switch multiple drawers off while a drawer other than the hidden
 * default one is still open, so no cashier is left with a drawer the POS can
 * no longer show or close.
 */
final class MultiTerminalDisableBlocker
{
    /**
     * @return list<string>
     */
    public function __invoke(): array
    {
        if (! Schema::hasTable('terminal_sessions')) {
            return [];
        }

        $open = TerminalSession::query()->open()->with('terminal')->get();

        $nonDefault = $open->filter(fn (TerminalSession $session) => ! $session->terminal?->is_default)->count();

        if ($nonDefault > 0 || $open->count() > 1) {
            $count = max($nonDefault, $open->count());

            return ["لا يمكن إيقاف ميزة منافذ البيع المتعددة لوجود {$count} منفذ بيع مفتوح. أغلق منافذ البيع وأنهِ الوردية أولاً."];
        }

        // All drawers closed but the shift still open: switching off now would
        // send the rest of the shift's money to a closed drawer.
        $openShiftIds = Shift::query()->where('closed', false)->whereNull('end_at')->pluck('id');
        $usedOtherDrawers = $openShiftIds->isNotEmpty() && TerminalSession::query()
            ->whereIn('shift_id', $openShiftIds)
            ->where(fn ($query) => $query
                ->whereHas('terminal', fn ($terminal) => $terminal->where('is_default', false))
                ->orWhereNotNull('closed_at'))
            ->exists();

        if ($usedOtherDrawers) {
            return ['لا يمكن إيقاف ميزة منافذ البيع المتعددة أثناء وردية استُخدمت فيها منافذ البيع. أنهِ الوردية أولاً.'];
        }

        return [];
    }
}
