<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Events\Orders\OrderCompleted;
use App\Models\Order;
use App\Orders\Tenders;

class OrderCompletionService
{
    public function __construct(
        private readonly OrderPaymentService $orderPaymentService,
        private readonly OrderCalculationService $orderCalculationService,
    ) {}

    public function complete(Order $order, array $paymentsData, bool $shouldPrint = false): Order
    {
        $order->load('items');

        if ($order->behavior()->recalculatesTotals()) {
            $this->orderCalculationService->calculateOrderTotals($order);
        }

        $this->orderPaymentService->pay($order, Tenders::fromArray($paymentsData), $order->shift_id);

        $order->behavior()->onClosed($order);

        // Update order status
        $order->update(['status' => OrderStatus::COMPLETED]);
        $order->refresh();

        // Fire event
        OrderCompleted::dispatch($order);

        return $order;
    }
}
