<?php

namespace App\Sync\Builders;

use App\Models\Shift;
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
            ->with('user')
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
        ];
    }
}
