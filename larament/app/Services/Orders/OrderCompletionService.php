<?php

namespace App\Services\Orders;

use App\Core\Events\OrderCompleting;
use App\Core\OrderExtensions;
use App\Enums\OrderStatus;
use App\Exceptions\OrderException;
use App\Models\Order;
use App\Orders\Tenders;

class OrderCompletionService
{
    public const GUESTS_REQUIRED_MESSAGE = 'يجب إدخال عدد الأفراد قبل إنهاء الطلب';

    public function __construct(
        private readonly OrderPaymentService $orderPaymentService,
        private readonly OrderCalculationService $orderCalculationService,
        private readonly OrderExtensions $extensions,
    ) {}

    public function complete(Order $order, array $paymentsData, bool $shouldPrint = false): Order
    {
        $order->load('items');

        if ($order->typeRow()->requiresGuests() && (int) $order->guests_count < 1) {
            throw new OrderException(self::GUESTS_REQUIRED_MESSAGE);
        }

        if ($order->behavior()->recalculatesTotals()) {
            $this->orderCalculationService->calculateOrderTotals($order);
        }

        $tendered = Tenders::fromArray($paymentsData);
        $tenders = $tendered;
        $afterPayment = [];
        foreach ($this->extensions->tenderSplitters() as $splitter) {
            $split = $splitter->split($order, $tenders);
            $tenders = $split->remaining;
            if ($split->afterPayment !== null) {
                $afterPayment[] = $split->afterPayment;
            }
        }

        $order = $this->orderPaymentService->pay($order, $tenders, $order->shift_id);
        foreach ($afterPayment as $callback) {
            $callback($order);
        }

        $paid = (float) $order->payments()->sum('amount');
        $remaining = round((float) $order->total - $paid, 2);

        if ($remaining > 0) {
            $typeRow = $order->typeRow();

            if ($paid <= 0) {
                if (! $typeRow->allowsPayLater()) {
                    throw new OrderException('هذا النوع لا يسمح بالإغلاق والدفع لاحقاً');
                }
            } else {
                if (! $typeRow->allowsPartialPayment()) {
                    throw new OrderException('المبلغ المدفوع أقل من إجمالي الطلب');
                }
            }

            if ($typeRow->requiresCustomerForCredit() && $order->customer_id === null) {
                throw new OrderException('يجب ربط الطلب بعميل قبل الدفع الآجل');
            }
        }

        // Last point a module can refuse the sale; a throw rolls back payments.
        OrderCompleting::dispatch($order, $tendered);

        $order->behavior()->onClosed($order);

        // Update order status
        $order->update(['status' => OrderStatus::COMPLETED]);
        $order->refresh();

        return $order;
    }
}
