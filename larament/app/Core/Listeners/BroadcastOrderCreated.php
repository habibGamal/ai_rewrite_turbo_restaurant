<?php

namespace App\Core\Listeners;

use App\Core\Events\OrderOpened;
use App\Events\Orders\OrderCreatedBroadcast;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Tells the POS screens after the order transaction commits. Never runs when
 * the transaction rolls back, so a failed sale is never announced.
 */
final class BroadcastOrderCreated implements ShouldHandleEventsAfterCommit
{
    public function handle(OrderOpened $event): void
    {
        OrderCreatedBroadcast::dispatch($event->order);
    }
}
