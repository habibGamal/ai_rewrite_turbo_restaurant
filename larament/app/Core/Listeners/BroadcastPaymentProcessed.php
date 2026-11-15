<?php

namespace App\Core\Listeners;

use App\Core\Events\PaymentRecorded;
use App\Events\Orders\PaymentProcessedBroadcast;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Tells the POS screens after the order transaction commits. Never runs when
 * the transaction rolls back, so a failed sale is never announced.
 */
final class BroadcastPaymentProcessed implements ShouldHandleEventsAfterCommit
{
    public function handle(PaymentRecorded $event): void
    {
        PaymentProcessedBroadcast::dispatch($event->payment, $event->order);
    }
}
