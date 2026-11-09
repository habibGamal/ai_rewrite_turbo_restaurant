<?php

namespace App\Orders;

use App\Enums\PaymentStatus;
use App\Models\Order;

final readonly class ReceiptPayment
{
    public function __construct(
        public float $paid,
        public float $remaining,
        public bool $partial,
    ) {}

    public static function forOrder(Order $order): self
    {
        $order->loadMissing('payments');

        $paid = round((float) $order->payments->sum('amount'), 2);
        $remaining = round(max(0, (float) $order->total - $paid), 2);

        return new self(
            paid: $paid,
            remaining: $remaining,
            partial: $order->payment_status === PaymentStatus::PARTIAL_PAID,
        );
    }

    /**
     * @return array{paid: float, remaining: float, partial: bool}
     */
    public function toArray(): array
    {
        return [
            'paid' => $this->paid,
            'remaining' => $this->remaining,
            'partial' => $this->partial,
        ];
    }
}
