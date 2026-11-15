<?php

namespace App\Modules\ExternalSellers;

use App\Core\Contracts\OrderTypeChangeGuard;
use App\Exceptions\OrderException;
use App\Models\Order;
use App\Models\OrderTypeRow;

/**
 * An order holding a live external seller order cannot move to a credit
 * (pay later) type: the seller's share is collected from the guest at once.
 */
final class CreditTypeChangeGuard implements OrderTypeChangeGuard
{
    public function assertCanChange(Order $order, OrderTypeRow $newType): void
    {
        if ($newType->allowsCredit() && $order->externalOrders()->where('status', '!=', 'cancelled')->exists()) {
            throw new OrderException('لا يمكن تغيير النوع مع وجود طلب خارجي');
        }
    }
}
