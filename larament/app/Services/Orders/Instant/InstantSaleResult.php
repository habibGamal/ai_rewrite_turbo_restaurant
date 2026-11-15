<?php

namespace App\Services\Orders\Instant;

use App\Models\Order;

final readonly class InstantSaleResult
{
    public function __construct(
        public Order $order,
        public float $paid,
        public float $change,
        public bool $duplicate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->order->id,
            'order_number' => (int) $this->order->order_number,
            'sub_total' => (float) $this->order->sub_total,
            'discount' => (float) $this->order->discount,
            'total' => (float) $this->order->total,
            'paid' => $this->paid,
            'change' => $this->change,
            'payment_status' => $this->order->payment_status->value,
        ];
    }
}
