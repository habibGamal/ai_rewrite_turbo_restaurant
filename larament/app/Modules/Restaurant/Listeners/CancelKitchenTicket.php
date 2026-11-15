<?php

namespace App\Modules\Restaurant\Listeners;

use App\Core\Events\OrderCancelled;
use App\Services\Kitchen\KitchenTicketService;

/**
 * In transaction: a cancelled order must never leave a live ticket.
 */
final class CancelKitchenTicket extends RestaurantListener
{
    public function __construct(private readonly KitchenTicketService $kitchen) {}

    public function handle(OrderCancelled $event): void
    {
        if ($this->enabled()) {
            $this->kitchen->cancelForOrder($event->order);
        }
    }
}
