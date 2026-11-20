<?php

namespace App\Modules\WebOrders\Behaviors;

use App\Orders\Behaviors\OrderTypeBehavior;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderTypeRow;
use App\Orders\OrderDisplay;
use App\Orders\OrderOpening;
use App\Orders\TabPresentation;

final class WebDeliveryBehavior implements OrderTypeBehavior
{
    public function cashierCanCreate(): bool
    {
        return false;
    }

    public function recalculatesTotals(): bool
    {
        return false;
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
        return true;
    }

    public function usesPosOrderNumber(): bool
    {
        return false;
    }

    public function display(OrderTypeRow $type): OrderDisplay
    {
        return OrderDisplay::forRow(
            $type,
            showCustomer: true,
            showAddress: true,
            showDriver: true,
            chargesDeliveryFee: true,
        );
    }

    public function presentation(OrderTypeRow $type): TabPresentation
    {
        return new TabPresentation('inbox', 'none', ['pending', 'processing', 'out_for_delivery'], 'order_number');
    }
}
