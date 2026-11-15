<?php

namespace App\Modules\ExternalSellers\Listeners;

use App\Core\Events\OrderCancelled;
use App\Exceptions\OrderException;
use App\Models\ExternalSeller;
use App\Models\Shift;
use App\Services\Orders\ExternalSellerSettlementService;

/**
 * In transaction: cancelling an order cancels its external orders, drops what
 * was collected for the seller and, when the seller was already paid more
 * than they are now owed, writes a reversing settlement in the open shift.
 * Throws when no shift is open, which rolls back the whole cancel.
 */
final class ReverseSellerSettlements
{
    public function __construct(private readonly ExternalSellerSettlementService $settlements) {}

    public function handle(OrderCancelled $event): void
    {
        $order = $event->order;

        foreach ($order->externalOrders()->orderBy('external_seller_id')->get() as $external) {
            $seller = ExternalSeller::whereKey($external->external_seller_id)->lockForUpdate()->firstOrFail();
            $external->collections()->delete();
            $external->update(['status' => 'cancelled']);
            $payable = $this->settlements->payable($seller);
            if ($payable < 0) {
                $shift = Shift::whereNull('end_at')->where('closed', false)->lockForUpdate()->first();
                if ($shift === null) {
                    throw new OrderException('يجب فتح شيفت لعكس مستحقات البائع');
                }
                $seller->settlements()->create([
                    'shift_id' => $shift->id,
                    'user_id' => auth()->id(),
                    'amount' => $payable,
                    'note' => 'عكس تسوية بعد إلغاء الطلب',
                ]);
            }
        }
    }
}
