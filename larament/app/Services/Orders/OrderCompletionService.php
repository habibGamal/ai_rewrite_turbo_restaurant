<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Events\Orders\OrderCompleted;
use App\Models\Order;

class OrderCompletionService
{
    public function __construct(
        private readonly OrderPaymentService $orderPaymentService,
        private readonly TableManagementService $tableManagementService,
        private readonly OrderCalculationService $orderCalculationService,
    ) {}

    public function complete(Order $order, array $paymentsData, bool $shouldPrint = false): Order
    {
        $order->load('items');

        // Calculate final totals
        $this->orderCalculationService->calculateOrderTotals($order);

        // Process payments
        $payments = $this->orderPaymentService->processMultiplePayments(
            $order,
            $paymentsData,
            $order->shift_id
        );

        // Free table if dine-in
        if ($order->type->requiresTable() && $order->table_number) {
            $this->tableManagementService->freeTable($order->table_number);
        }

        // Update order status
        $order->update(['status' => OrderStatus::COMPLETED]);
        $order->refresh();

        // Fire event
        OrderCompleted::dispatch($order);

        return $order;
    }
}
