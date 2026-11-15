<?php

namespace App\Core\Events;

use App\Models\Order;
use App\Orders\Tenders;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The order is about to be marked completed: payments are recorded but the
 * status has not flipped yet. In-transaction listeners may veto the sale by
 * throwing, which rolls back payments and stock.
 */
final class OrderCompleting
{
    use Dispatchable;

    public function __construct(
        public readonly Order $order,
        public readonly Tenders $tenders,
    ) {}
}
