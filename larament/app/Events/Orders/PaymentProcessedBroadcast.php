<?php

namespace App\Events\Orders;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentProcessedBroadcast implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Payment $payment,
        public readonly Order $order
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('shift.'.$this->order->shift_id),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'payment' => [
                'id' => $this->payment->id,
                'amount' => $this->payment->amount,
                'method' => $this->payment->method->label(),
                'order_id' => $this->order->id,
                'processed_at' => $this->payment->created_at->format('H:i:s'),
            ],
        ];
    }

    public function broadcastAs(): string
    {
        return 'payment.processed';
    }
}
