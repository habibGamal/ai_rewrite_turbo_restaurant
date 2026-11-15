<?php

namespace App\Core\Events;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A payment row was written for the order.
 */
final class PaymentRecorded
{
    use Dispatchable;

    public function __construct(
        public readonly Payment $payment,
        public readonly Order $order,
    ) {}
}
