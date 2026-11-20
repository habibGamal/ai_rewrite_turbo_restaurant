<?php

namespace App\Sync\Builders;

use App\Models\Shift;
use App\Models\TerminalSession;
use App\Models\TerminalSessionHandover;
use App\Sync\Builders\Concerns\IgnoresKey;
use App\Sync\DocumentType;
use App\Sync\Payload;

class ShiftBuilder implements DocumentBuilder
{
    use IgnoresKey;

    public function type(): DocumentType
    {
        return DocumentType::Shift;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(string $uuid): ?array
    {
        /** @var Shift|null $shift */
        $shift = Shift::query()
            ->with(['user', 'terminalSessions.terminal', 'terminalSessions.user', 'terminalSessions.closedBy', 'terminalSessions.handovers.fromUser', 'terminalSessions.handovers.toUser'])
            ->where('uuid', $uuid)
            ->first();

        if (! $shift) {
            return null;
        }

        $user = $shift->user;

        return [
            'business_date' => Payload::date($shift->getAttribute('business_date')),
            'user_uuid' => $user ? $user->getAttribute('uuid') : null,
            'start_at' => Payload::ts($shift->start_at),
            'end_at' => Payload::ts($shift->end_at),
            'start_cash' => Payload::money($shift->start_cash),
            'end_cash' => Payload::money($shift->end_cash),
            'real_cash' => Payload::money($shift->real_cash),
            'losses_amount' => Payload::money($shift->losses_amount),
            'has_deficit' => (bool) $shift->has_deficit,
            'closed' => (bool) $shift->closed,
            'money_edited_at' => Payload::ts($shift->money_edited_at),
            'money_edited_count' => (int) $shift->money_edited_count,
            // Cash drawers of the shift (one hidden default drawer while
            // multiple terminals are off).
            'terminal_sessions' => $shift->terminalSessions
                ->sortBy('opened_at')
                ->map(fn (TerminalSession $session): array => [
                    'uuid' => $session->uuid,
                    'terminal_name' => $session->terminal?->name,
                    'terminal_is_default' => (bool) $session->terminal?->is_default,
                    'user_uuid' => $session->user?->getAttribute('uuid'),
                    'closed_by_uuid' => $session->closedBy?->getAttribute('uuid'),
                    'opening_cash' => Payload::money($session->opening_cash),
                    'expected_cash' => Payload::money($session->expected_cash),
                    'opened_at' => Payload::ts($session->opened_at),
                    'closed_at' => Payload::ts($session->closed_at),
                    'close_note' => $session->close_note,
                    'handovers' => $session->handovers
                        ->sortBy('handed_at')
                        ->map(fn (TerminalSessionHandover $handover): array => [
                            'from_user_uuid' => $handover->fromUser?->getAttribute('uuid'),
                            'to_user_uuid' => $handover->toUser?->getAttribute('uuid'),
                            'note' => $handover->note,
                            'handed_at' => Payload::ts($handover->handed_at),
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }
}
