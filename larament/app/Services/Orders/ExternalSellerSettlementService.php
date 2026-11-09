<?php

namespace App\Services\Orders;

use App\Exceptions\OrderException;
use App\Models\ExternalOrderCollection;
use App\Models\ExternalSeller;
use App\Models\ExternalSellerSettlement;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

class ExternalSellerSettlementService
{
    public function payable(ExternalSeller $seller): float
    {
        $collected = ExternalOrderCollection::query()->whereIn('external_order_id', $seller->orders()->select('id'))->sum('amount');

        return round((float) $collected - (float) $seller->settlements()->sum('amount'), 2);
    }

    public function pay(int $sellerId, float $amount, ?string $note): ExternalSellerSettlement
    {
        return DB::transaction(function () use ($sellerId, $amount, $note) {
            $seller = ExternalSeller::whereKey($sellerId)->lockForUpdate()->firstOrFail();
            $shift = Shift::whereNull('end_at')->where('closed', false)->lockForUpdate()->first();
            if ($shift === null) {
                throw new OrderException('يجب فتح شيفت قبل دفع مستحقات البائع');
            }
            if ($amount <= 0 || round($amount, 2) !== $amount || $amount > $this->payable($seller)) {
                throw new OrderException('المبلغ يتجاوز مستحقات البائع أو غير صالح');
            }

            return $seller->settlements()->create([
                'shift_id' => $shift->id,
                'amount' => $amount,
                'user_id' => auth()->id(),
                'note' => $note,
            ]);
        });
    }
}
