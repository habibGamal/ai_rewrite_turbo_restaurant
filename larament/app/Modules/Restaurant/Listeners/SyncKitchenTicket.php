<?php

namespace App\Modules\Restaurant\Listeners;

use App\Core\Events\OrderItemsChanged;
use App\Modules\Restaurant\Services\KitchenTicketService;

/**
 * In transaction: the kitchen ticket must match the saved items, and
 * syncOrder relies on the order row lock the caller holds.
 */
final class SyncKitchenTicket extends RestaurantListener
{
    public function __construct(private readonly KitchenTicketService $kitchen) {}

    public function handle(OrderItemsChanged $event): void
    {
        if ($this->enabled()) {
            $this->kitchen->syncOrder($event->order);
        }
    }
}
