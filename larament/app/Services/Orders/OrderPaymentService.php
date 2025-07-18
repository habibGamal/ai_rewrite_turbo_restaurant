<?php

namespace App\Services\Orders;

use App\DTOs\Orders\PaymentDTO;
use App\Enums\PaymentStatus;
use App\Events\Orders\PaymentProcessed;
use App\Models\Order;
use App\Models\Payment;
use App\Repositories\Contracts\PaymentRepositoryInterface;

class OrderPaymentService
{
    public function __construct(
        private readonly PaymentRepositoryInterface $paymentRepository,
    ) {}

    public function processPayment(Order $order, PaymentDTO $paymentDTO): Order
    {
        // Create payment record
        $payment = $this->paymentRepository->create($paymentDTO);

        // Update order payment status
        $this->updateOrderPaymentStatus($order);

        // Fire event
        PaymentProcessed::dispatch($payment, $order);

        return $order->refresh();
    }

    public function processMultiplePayments(Order $order, array $paymentsData, int $shiftId): array
    {
        $payments = [];
        $totalPaid = 0;

        foreach ($paymentsData as $method => $amount) {
            if ($amount > 0) {
                $paymentDTO = new PaymentDTO(
                    amount: $amount,
                    method: \App\Enums\PaymentMethod::from($method),
                    orderId: $order->id,
                    shiftId: $shiftId
                );

                $payment = $this->paymentRepository->create($paymentDTO);
                $payments[] = $payment;
                $totalPaid += $amount;

                // Fire event for each payment
                PaymentProcessed::dispatch($payment, $order);
            }
        }

        // Update order payment status
        $this->updateOrderPaymentStatus($order);

        return $payments;
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
