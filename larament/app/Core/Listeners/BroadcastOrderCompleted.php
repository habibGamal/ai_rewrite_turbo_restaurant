<?php

namespace App\Core\Listeners;

use App\Core\Events\OrderCompleted;
use App\Events\Orders\OrderCompletedBroadcast;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Tells the POS screens after the order transaction commits. Never runs when
 * the transaction rolls back, so a failed sale is never announced.
 */
final class BroadcastOrderCompleted implements ShouldHandleEventsAfterCommit
{
    public function handle(OrderCompleted $event): void
    {
        OrderCompletedBroadcast::dispatch($event->order);
    }
}
