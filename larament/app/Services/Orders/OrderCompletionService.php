<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Events\Orders\OrderCompleted;
use App\Exceptions\OrderException;
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

        $order = $this->orderPaymentService->pay($order, Tenders::fromArray($paymentsData), $order->shift_id);

        $remaining = round((float) $order->total - (float) $order->payments()->sum('amount'), 2);

        if ($remaining > 0) {
            if (! $order->behavior()->allowsPartialPayment()) {
                throw new OrderException('المبلغ المدفوع أقل من إجمالي الطلب');
            }

            if ($order->customer_id === null) {
                throw new OrderException('يجب ربط الطلب بعميل قبل الدفع الجزئي');
            }
        }

        $order->behavior()->onClosed($order);

        // Update order status
        $order->update(['status' => OrderStatus::COMPLETED]);
        $order->refresh();

        // Fire event
        OrderCompleted::dispatch($order);

        return $order;
    }
}
