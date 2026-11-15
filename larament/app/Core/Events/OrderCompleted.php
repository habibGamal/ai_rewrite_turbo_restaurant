<?php

namespace App\Core\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The order is completed. From the POS this fires after stock and profit are
 * written; from the website flow it fires when the web order is delivered.
 * Dispatched inside the transaction.
 */
final class OrderCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly Order $order,
        public readonly bool $shouldPrint = false,
    ) {}
}
