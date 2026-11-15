<?php

namespace App\Core\Listeners;

use App\Core\Events\OrderTypeChanged;
use App\Events\Orders\OrderTypeChangedBroadcast;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Tells the POS screens after the order transaction commits. Never runs when
 * the transaction rolls back, so a failed sale is never announced.
 */
final class BroadcastOrderTypeChanged implements ShouldHandleEventsAfterCommit
{
    public function handle(OrderTypeChanged $event): void
    {
        OrderTypeChangedBroadcast::dispatch($event->order);
    }
}
