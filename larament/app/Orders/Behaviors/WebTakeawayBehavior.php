<?php

namespace App\Orders\Behaviors;

use App\Models\Order;
use App\Models\OrderTypeRow;
use App\Orders\OrderDisplay;
use App\Orders\OrderOpening;

final class WebTakeawayBehavior implements OrderTypeBehavior
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
        return 0;
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
        return OrderDisplay::forRow($type, showCustomer: true);
    }
}
