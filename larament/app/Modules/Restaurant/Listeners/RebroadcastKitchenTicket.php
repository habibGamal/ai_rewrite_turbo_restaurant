<?php

namespace App\Modules\Restaurant\Listeners;

use App\Core\Events\OrderTypeChanged;
use App\Modules\Restaurant\Services\KitchenTicketService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * After commit: only tells the KDS screens the ticket's order type changed.
 */
final class RebroadcastKitchenTicket extends RestaurantListener implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly KitchenTicketService $kitchen) {}

    public function handle(OrderTypeChanged $event): void
    {
        if ($this->enabled()) {
            $this->kitchen->rebroadcastForOrder($event->order);
        }
    }
}
