<?php

namespace App\Events\Orders;

use App\Models\Order;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderChargesChangedBroadcast implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly string $kind,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('shift.'.$this->order->shift_id)];
    }

    public function broadcastAs(): string
    {
        return 'order.charges_changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'kind' => $this->kind,
            'total' => $this->order->total,
        ];
    }
}
