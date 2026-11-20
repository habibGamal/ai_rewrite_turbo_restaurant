<?php

namespace App\Orders\Behaviors;

use App\Models\Order;
use App\Models\OrderTypeRow;
use App\Orders\OrderDisplay;
use App\Orders\OrderOpening;
use App\Orders\TabPresentation;

final class CompaniesBehavior implements OrderTypeBehavior
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
        return 0;
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

    public function display(OrderTypeRow $type): OrderDisplay
    {
        return OrderDisplay::forRow($type, showCustomer: true);
    }

    public function presentation(OrderTypeRow $type): TabPresentation
    {
        return new TabPresentation('cards', 'immediate', ['processing'], 'order_number');
    }
}
