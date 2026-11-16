<?php

namespace App\Terminals;

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

            return ["لا يمكن إيقاف ميزة الأدراج المتعددة لوجود {$count} درج مفتوح. اقفل الأدراج وأنهِ الوردية الأول."];
        }

        return [];
    }
}
