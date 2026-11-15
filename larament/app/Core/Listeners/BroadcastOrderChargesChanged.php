<?php

namespace App\Core\Listeners;

use App\Core\Events\OrderChargesChanged;
use App\Events\Orders\OrderChargesChangedBroadcast;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Tells the other POS screens after the order transaction commits, so they
 * refresh the order's totals. Never runs when the transaction rolls back.
 */
final class BroadcastOrderChargesChanged implements ShouldHandleEventsAfterCommit
{
    public function handle(OrderChargesChanged $event): void
    {
        OrderChargesChangedBroadcast::dispatch($event->order, $event->kind);
    }
}
