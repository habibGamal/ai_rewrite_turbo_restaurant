<?php

namespace App\Core\Contracts;

use App\Core\TenderSplit;
use App\Models\Order;
use App\Orders\Tenders;

/**
 * Lets a module take its share of what the guest paid before core records the
 * order's own payments (External sellers takes the seller's part with GuestBill).
 * A listener cannot change the tenders, so this is a contract core calls
 * directly, in registration order, inside the completion transaction.
 */
interface TenderSplitter
{
    /**
     * Return the tenders left for the order. Throw to refuse the payment.
     * Writes that need the recorded payments go in TenderSplit::$afterPayment.
     */
    public function split(Order $order, Tenders $tenders): TenderSplit;
}
