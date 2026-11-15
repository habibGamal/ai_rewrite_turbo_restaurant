<?php

namespace App\Core\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The order was cancelled (POS cancel or web reject). Dispatched inside the
 * transaction after payments were removed and stock restored, so a throwing
 * listener rolls back the whole cancel.
 */
final class OrderCancelled
{
    use Dispatchable;

    public function __construct(
        public readonly Order $order,
        public readonly bool $wasCompleted = false,
        public readonly string $reason = '',
    ) {}
}
