<?php

namespace App\Modules\WebOrders\DTOs;

use App\Models\Order;

/**
 * Result of a place-order call: the POS order, and whether it was already
 * saved by an earlier attempt of the same website order.
 */
final class PlacedWebOrder
{
    public function __construct(
        public readonly Order $order,
        public readonly bool $alreadyReceived,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toResponse(): array
    {
        return [
            'message' => $this->alreadyReceived ? 'تم استلام الطلب من قبل' : 'تم إنشاء الطلب بنجاح',
            'already_received' => $this->alreadyReceived,
            'order' => [
                'id' => $this->order->id,
                'order_number' => $this->order->order_number,
                'status' => $this->order->status->value,
                'total' => (float) $this->order->total,
            ],
        ];
    }
}
