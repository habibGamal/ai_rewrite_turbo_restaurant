<?php

namespace App\Events\Kitchen;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class KitchenTicketUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public const REASON_NEW = 'new';

    public const REASON_CHANGED = 'changed';

    public const REASON_CANCELLED = 'cancelled';

    public const REASON_DONE = 'done';

    /**
     * @param  'new'|'changed'|'cancelled'|'done'  $reason
     * @param  list<int>  $stationIds
     */
    public function __construct(
        public readonly int $ticketId,
        public readonly int $orderNumber,
        public readonly string $reason,
        public readonly array $stationIds,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('kitchen'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'KitchenTicketUpdated';
    }

    /**
     * @return array{ticket_id: int, order_number: int, reason: string, station_ids: list<int>}
     */
    public function broadcastWith(): array
    {
        return [
            'ticket_id' => $this->ticketId,
            'order_number' => $this->orderNumber,
            'reason' => $this->reason,
            'station_ids' => $this->stationIds,
        ];
    }
}
