<?php

namespace App\Core\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The order's live item set changed: items were saved from the POS, or a
 * pending web order was accepted and its items became live. Dispatched inside
 * the transaction that holds the order row lock.
 */
final class OrderItemsChanged
{
    use Dispatchable;

    /**
     * @param  array<int|string, mixed>  $differences  item differences from ShiftLoggingService (empty when the whole order became live)
     */
    public function __construct(
        public readonly Order $order,
        public readonly array $differences = [],
    ) {}
}
