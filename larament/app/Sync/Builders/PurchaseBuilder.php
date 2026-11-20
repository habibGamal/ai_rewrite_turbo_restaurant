<?php

namespace App\Sync\Builders;

use App\Models\PurchaseInvoice;
use App\Models\ReturnPurchaseInvoice;
use App\Sync\Builders\Concerns\IgnoresKey;
use App\Sync\DocumentType;
use App\Sync\Payload;

class PurchaseBuilder implements DocumentBuilder
{
    use IgnoresKey;

    public function __construct(
        protected DocumentType $type = DocumentType::PurchaseInvoice
    ) {}

    public function type(): DocumentType
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(string $uuid): ?array
    {
        /** @var PurchaseInvoice|ReturnPurchaseInvoice|null $record */
        $record = $this->type === DocumentType::PurchaseReturn
            ? ReturnPurchaseInvoice::query()->where('uuid', $uuid)->first()
            : PurchaseInvoice::query()->where('uuid', $uuid)->first();

        if (! $record) {
            return null;
        }

        $items = [];
        foreach ($record->items()->get() as $item) {
            /** @var \App\Models\Product|null $product */
            $product = $item->product()->first();

            $items[] = [
                'product_uuid' => $product ? $product->getAttribute('uuid') : null,
                'quantity' => Payload::qty($item->quantity),
                'price' => Payload::money($item->price),
                'total' => Payload::money($item->total),
            ];
        }

        /** @var \App\Models\Supplier|null $supplier */
        $supplier = $record->supplier()->first();

        return [
            'business_date' => Payload::date($record->getAttribute('business_date')),
            'closed_at' => Payload::ts($record->closed_at),
            'supplier_uuid' => $supplier ? $supplier->getAttribute('uuid') : null,
            'total' => Payload::money($record->total),
            'items' => $items,
        ];
    }
}
