<?php

namespace App\Sync\Builders;

use App\Models\Waste;
use App\Models\WastedItem;
use App\Sync\Builders\Concerns\IgnoresKey;
use App\Sync\DocumentType;
use App\Sync\Payload;

class WasteBuilder implements DocumentBuilder
{
    use IgnoresKey;

    public function type(): DocumentType
    {
        return DocumentType::Waste;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(string $uuid): ?array
    {
        /** @var Waste|null $waste */
        $waste = Waste::query()
            ->where('uuid', $uuid)
            ->first();

        if (! $waste) {
            return null;
        }

        $items = [];
        /** @var WastedItem $item */
        foreach ($waste->items()->get() as $item) {
            /** @var \App\Models\Product|null $product */
            $product = $item->product()->first();

            $items[] = [
                'product_uuid' => $product ? $product->getAttribute('uuid') : null,
                'quantity' => Payload::qty($item->quantity),
                'price' => Payload::money($item->price),
                'total' => Payload::money($item->total),
            ];
        }

        return [
            'business_date' => Payload::date($waste->getAttribute('business_date')),
            'closed_at' => Payload::ts($waste->closed_at),
            'total' => Payload::money($waste->total),
            'items' => $items,
        ];
    }
}
