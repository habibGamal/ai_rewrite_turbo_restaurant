<?php

namespace App\Modules\Restaurant\Events;

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
        public readonly ?int $shiftId = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('kitchen'),
        ];

        if ($this->shiftId !== null) {
            $channels[] = new PrivateChannel('shift.'.$this->shiftId);
        }

        return $channels;
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
