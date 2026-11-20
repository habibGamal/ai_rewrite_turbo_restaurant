<?php

namespace App\Sync\Builders;

use App\Models\Stocktaking;
use App\Models\StocktakingItem;
use App\Sync\Builders\Concerns\IgnoresKey;
use App\Sync\DocumentType;
use App\Sync\Payload;

class StocktakingBuilder implements DocumentBuilder
{
    use IgnoresKey;

    public function type(): DocumentType
    {
        return DocumentType::Stocktaking;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(string $uuid): ?array
    {
        /** @var Stocktaking|null $stocktaking */
        $stocktaking = Stocktaking::query()
            ->where('uuid', $uuid)
            ->first();

        if (! $stocktaking) {
            return null;
        }

        $items = [];
        /** @var StocktakingItem $item */
        foreach ($stocktaking->items()->get() as $item) {
            /** @var \App\Models\Product|null $product */
            $product = $item->product()->first();

            $items[] = [
                'product_uuid' => $product ? $product->getAttribute('uuid') : null,
                'stock_quantity' => Payload::qty($item->stock_quantity),
                'real_quantity' => Payload::qty($item->real_quantity),
                'price' => Payload::money($item->price),
                'total' => Payload::money($item->total),
            ];
        }

        return [
            'business_date' => Payload::date($stocktaking->getAttribute('business_date')),
            'closed_at' => Payload::ts($stocktaking->closed_at),
            'total' => Payload::money($stocktaking->total),
            'items' => $items,
        ];
    }
}
