<?php

namespace App\Sync\Builders;

use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\Refund;
use App\Sync\Builders\Concerns\IgnoresKey;
use App\Sync\DocumentType;
use App\Sync\Payload;

class OrderReturnBuilder implements DocumentBuilder
{
    use IgnoresKey;

    public function type(): DocumentType
    {
        return DocumentType::OrderReturn;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(string $uuid): ?array
    {
        /** @var OrderReturn|null $orderReturn */
        $orderReturn = OrderReturn::query()
            ->with(['order', 'shift', 'items.orderItem', 'items.orderFee', 'items.orderEquipment', 'refunds'])
            ->where('uuid', $uuid)
            ->first();

        if (! $orderReturn) {
            return null;
        }

        $items = [];
        /** @var OrderReturnItem $item */
        foreach ($orderReturn->items as $item) {
            $orderItem = $item->orderItem;
            $items[] = [
                'kind' => $item->kind(),
                'order_item_uuid' => $orderItem ? $orderItem->getAttribute('uuid') : null,
                'order_fee_uuid' => $item->orderFee?->getAttribute('uuid'),
                'order_equipment_uuid' => $item->orderEquipment?->getAttribute('uuid'),
                'quantity' => Payload::qty($item->quantity),
                'refund_amount' => Payload::money($item->refund_amount),
                'restocked' => (bool) $item->restocked,
            ];
        }

        $refunds = [];
        /** @var Refund $refund */
        foreach ($orderReturn->refunds as $refund) {
            $refunds[] = [
                'method' => $refund->method->value,
                'amount' => Payload::money($refund->amount),
            ];
        }

        $order = $orderReturn->order;
        $shift = $orderReturn->shift;

        return [
            'order_uuid' => $order ? $order->getAttribute('uuid') : null,
            'shift_uuid' => $shift ? $shift->getAttribute('uuid') : null,
            'business_date' => Payload::date($shift ? $shift->getAttribute('business_date') : null),
            'total_refund' => Payload::money($orderReturn->total_refund),
            'reason' => $orderReturn->reason,
            'reverse_stock' => (bool) $orderReturn->reverse_stock,
            'is_exchange' => $orderReturn->exchange_order_id !== null,
            'items' => $items,
            'refunds' => $refunds,
        ];
    }
}
