<?php

namespace App\Modules\Restaurant\Listeners;

use App\Core\Events\OrderCompleted;
use App\Services\Kitchen\KitchenTicketService;

/**
 * In transaction: writes ticket state; honours KDS_AUTO_FINISH_ON_COMPLETE.
 */
final class AutoFinishKitchenTicket extends RestaurantListener
{
    public function __construct(private readonly KitchenTicketService $kitchen) {}

    public function handle(OrderCompleted $event): void
    {
        if ($this->enabled()) {
            $this->kitchen->autoFinishForOrder($event->order);
        }
    }
}
