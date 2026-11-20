<?php

namespace App\Modules\ExternalSellers;

use App\Core\Contracts\TenderSplitter;
use App\Core\TenderSplit;
use App\Models\ExternalOrderCollection;
use App\Models\Order;
use App\Orders\GuestBill;
use App\Orders\Tenders;

/**
 * When the guest's bill includes external seller orders, the seller's share
 * is taken out of the tenders first (GuestBill) and recorded as collections
 * once the order's own payments are written.
 */
final class GuestBillTenderSplitter implements TenderSplitter
{
    public function __construct(private readonly GuestBill $guestBill) {}

    public function split(Order $order, Tenders $tenders): TenderSplit
    {
        if (! $order->externalOrders()->where('status', '!=', 'cancelled')->exists()) {
            return TenderSplit::untouched($tenders);
        }

        $split = $this->guestBill->split($order, $tenders);
        $collections = $split['collections'];

        return new TenderSplit($split['restaurant'], function (Order $paid) use ($collections): void {
            foreach ($collections as $collection) {
                ExternalOrderCollection::create($collection + [
                    'shift_id' => $paid->shift_id,
                    'user_id' => auth()->id(),
                ]);
            }
        });
    }
}
