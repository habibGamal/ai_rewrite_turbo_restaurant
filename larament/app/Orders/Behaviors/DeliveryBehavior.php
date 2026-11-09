<?php

namespace App\Orders\Behaviors;

use App\Models\Customer;
use App\Models\Order;
use App\Orders\OrderOpening;

final class DeliveryBehavior implements OrderTypeBehavior
{
    public function cashierCanCreate(): bool
    {
        return true;
    }

    public function recalculatesTotals(): bool
    {
        return true;
    }

    public function serviceAmount(Order $order, float $subtotal): float
    {
        $order->loadMissing('customer');
        $customer = $order->customer;

        if (! $customer instanceof Customer) {
            return 0.0;
        }

        return (float) $customer->delivery_cost;
    }

    public function onOpened(Order $order, OrderOpening $opening): void {}

    public function onClosed(Order $order): void {}

    public function carriesAcrossShifts(): bool
    {
        return false;
    }

    public function usesPosOrderNumber(): bool
    {
        return true;
    }
}
