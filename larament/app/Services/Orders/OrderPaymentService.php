<?php

namespace App\Services\Orders;

use App\DTOs\Orders\PaymentDTO;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Events\Orders\PaymentProcessed;
use App\Exceptions\OrderException;
use App\Models\Order;
use App\Orders\Tenders;
use App\Repositories\Contracts\PaymentRepositoryInterface;

class OrderPaymentService
{
    public function __construct(
        private readonly PaymentRepositoryInterface $paymentRepository,
    ) {}

    public function pay(Order $order, Tenders $tenders, int $shiftId): Order
    {
        if ($tenders->isEmpty()) {
            $this->updateOrderPaymentStatus($order);

            return $order->refresh();
        }

        $totalPaid = $this->paymentRepository->getTotalPaidForOrder($order->id);
        $remaining = $order->total - $totalPaid;

        if ($tenders->count() === 1) {
            foreach ($tenders->amounts() as $method => $amount) {
                $actualPaymentAmount = min($amount, $remaining);

                if ($actualPaymentAmount > 0) {
                    $this->recordPayment($order, (string) $method, $actualPaymentAmount, $shiftId);
                    $this->updateOrderPaymentStatus($order);
                }
            }

            return $order->refresh();
        }

        $totalNewPayments = $tenders->total();

        if (($totalPaid + $totalNewPayments) > $order->total) {
            throw new OrderException(
                "إجمالي المدفوعات ({$totalPaid} + {$totalNewPayments} = ".($totalPaid + $totalNewPayments).
                ') يتجاوز إجمالي الطلب ('.$order->total.')'
            );
        }

        foreach ($tenders->amounts() as $method => $amount) {
            $this->recordPayment($order, (string) $method, $amount, $shiftId);
        }

        $this->updateOrderPaymentStatus($order);

        return $order->refresh();
    }

    private function recordPayment(Order $order, string $method, float $amount, int $shiftId): void
    {
        $payment = $this->paymentRepository->create(new PaymentDTO(
            amount: $amount,
            method: PaymentMethod::from($method),
            orderId: $order->id,
            shiftId: $shiftId,
        ));

        PaymentProcessed::dispatch($payment, $order);
    }

    private function updateOrderPaymentStatus(Order $order): void
    {
        $totalPaid = $this->paymentRepository->getTotalPaidForOrder($order->id);
        if ($totalPaid >= $order->total) {
            $paymentStatus = PaymentStatus::FULL_PAID;
        } elseif ($totalPaid > 0) {
            $paymentStatus = PaymentStatus::PARTIAL_PAID;
        } else {
            $paymentStatus = PaymentStatus::PENDING;
        }

        $order->update(['payment_status' => $paymentStatus]);
    }
}
