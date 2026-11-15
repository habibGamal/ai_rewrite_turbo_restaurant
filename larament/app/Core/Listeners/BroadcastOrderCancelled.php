<?php

namespace App\Core\Listeners;

use App\Core\Events\OrderCancelled;
use App\Events\Orders\OrderCancelledBroadcast;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Tells the POS screens after the order transaction commits. Never runs when
 * the transaction rolls back, so a failed sale is never announced.
 */
final class BroadcastOrderCancelled implements ShouldHandleEventsAfterCommit
{
    public function handle(OrderCancelled $event): void
    {
        OrderCancelledBroadcast::dispatch($event->order, $event->reason);
    }
}
