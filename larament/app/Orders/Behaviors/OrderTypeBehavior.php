<?php

namespace App\Orders\Behaviors;

use App\Models\Order;
use App\Orders\OrderOpening;

interface OrderTypeBehavior
{
    public function cashierCanCreate(): bool;

    public function recalculatesTotals(): bool;

    public function serviceAmount(Order $order, float $subtotal): float;

    public function onOpened(Order $order, OrderOpening $opening): void;

    public function onClosed(Order $order): void;

    public function carriesAcrossShifts(): bool;

    public function usesPosOrderNumber(): bool;
}
