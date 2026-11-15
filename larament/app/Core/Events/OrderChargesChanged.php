<?php

namespace App\Core\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The order's extra charges changed: a custom fee or an equipment line was
 * added, changed or removed, and the totals were recalculated. Dispatched
 * inside the transaction that holds the order row lock. No kitchen ticket.
 */
final class OrderChargesChanged
{
    use Dispatchable;

    public const FEES = 'fees';

    public const EQUIPMENT = 'equipment';

    /**
     * @param  self::FEES|self::EQUIPMENT  $kind
     */
    public function __construct(
        public readonly Order $order,
        public readonly string $kind,
    ) {}
}
