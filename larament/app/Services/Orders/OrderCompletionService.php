<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Events\Orders\OrderCompleted;
use App\Exceptions\OrderException;
use App\Models\ExternalOrderCollection;
use App\Models\Order;
use App\Orders\GuestBill;
use App\Orders\Tenders;

class OrderCompletionService
{
    public function __construct(
        private readonly OrderPaymentService $orderPaymentService,
        private readonly OrderCalculationService $orderCalculationService,
        private readonly GuestBill $guestBill,
    ) {}

    public function complete(Order $order, array $paymentsData, bool $shouldPrint = false): Order
    {
        $order->load('items');

        if ($order->behavior()->recalculatesTotals()) {
            $this->orderCalculationService->calculateOrderTotals($order);
        }

        $tenders = Tenders::fromArray($paymentsData);
        $collections = [];
        if ($order->externalOrders()->where('status', '!=', 'cancelled')->exists()) {
            $split = $this->guestBill->split($order, $tenders);
            $tenders = $split['restaurant'];
            $collections = $split['collections'];
        }

        $order = $this->orderPaymentService->pay($order, $tenders, $order->shift_id);
        foreach ($collections as $collection) {
            ExternalOrderCollection::create($collection + [
                'shift_id' => $order->shift_id,
                'user_id' => auth()->id(),
            ]);
        }

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
