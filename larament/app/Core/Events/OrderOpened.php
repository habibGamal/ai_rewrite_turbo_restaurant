<?php

namespace App\Core\Events;

use App\Models\Order;
use App\Orders\OrderOpening;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new POS order was opened. Dispatched inside the opening transaction,
 * right after the order type's onOpened hook.
 */
final class OrderOpened
{
    use Dispatchable;

    public function __construct(
        public readonly Order $order,
        public readonly OrderOpening $opening,
    ) {}
}
