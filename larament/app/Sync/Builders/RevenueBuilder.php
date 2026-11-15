<?php

namespace App\Sync\Builders;

use App\Models\Revenue;
use App\Sync\Builders\Concerns\IgnoresKey;
use App\Sync\DocumentType;
use App\Sync\Payload;

class RevenueBuilder implements DocumentBuilder
{
    use IgnoresKey;

    public function type(): DocumentType
    {
        return DocumentType::Revenue;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(string $uuid): ?array
    {
        /** @var Revenue|null $revenue */
        $revenue = Revenue::query()
            ->with(['shift', 'revenueType', 'user'])
            ->where('uuid', $uuid)
            ->first();

        if (! $revenue) {
            return null;
        }

        $shift = $revenue->shift;
        $type = $revenue->revenueType;
        $user = $revenue->user;

        return [
            'shift_uuid' => $shift?->getAttribute('uuid'),
            'business_date' => Payload::date($shift?->getAttribute('business_date')),
            'revenue_type_uuid' => $type?->uuid,
            'user_uuid' => $user?->getAttribute('uuid'),
            'amount' => Payload::money($revenue->amount),
            'method' => $revenue->method->value,
            'notes' => $revenue->notes,
        ];
    }
}
