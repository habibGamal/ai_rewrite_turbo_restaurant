<?php

namespace App\Core\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The order moved to another order type. Dispatched inside the transaction,
 * after totals were recalculated for the new type.
 */
final class OrderTypeChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Order $order,
        public readonly string $oldType,
        public readonly string $newType,
    ) {}
}
