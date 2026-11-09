<?php

namespace App\Orders\Behaviors;

use App\Models\Order;
use App\Models\OrderTypeRow;
use App\Orders\OrderDisplay;
use App\Orders\OrderOpening;
use App\Orders\TabPresentation;

interface OrderTypeBehavior
{
    public function cashierCanCreate(): bool;

    public function recalculatesTotals(): bool;

    public function serviceAmount(Order $order, float $subtotal): float;

    public function onOpened(Order $order, OrderOpening $opening): void;

    public function onClosed(Order $order): void;

    public function carriesAcrossShifts(): bool;

    public function allowsPartialPayment(): bool;

    public function usesPosOrderNumber(): bool;

    public function display(OrderTypeRow $type): OrderDisplay;

    public function presentation(OrderTypeRow $type): TabPresentation;
}
