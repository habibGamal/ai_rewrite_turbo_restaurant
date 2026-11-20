<?php

namespace App\Core\Contracts;

use App\Models\Order;
use App\Models\OrderTypeRow;

/**
 * Lets a module refuse an order type change before anything is written.
 */
interface OrderTypeChangeGuard
{
    /**
     * Throw an OrderException to refuse the change.
     */
    public function assertCanChange(Order $order, OrderTypeRow $newType): void;
}
