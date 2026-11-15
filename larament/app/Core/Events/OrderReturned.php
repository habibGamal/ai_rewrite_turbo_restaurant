<?php

namespace App\Core\Events;

use App\Models\Order;
use App\Models\OrderReturn;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Items of a completed order were returned. Dispatched inside the return
 * transaction after refunds and stock reversal.
 */
final class OrderReturned
{
    use Dispatchable;

    public function __construct(
        public readonly OrderReturn $orderReturn,
        public readonly Order $order,
    ) {}
}
