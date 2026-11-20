<?php

namespace App\Orders;

use App\Exceptions\OrderException;
use App\Models\Order;

final class GuestBill
{
    public function due(Order $order): float
    {
        $restaurant = max(0, (float) $order->total - (float) $order->payments()->sum('amount'));
        $external = $order->externalOrders()->where('status', '!=', 'cancelled')->withSum('collections', 'amount')->get();

        return round($restaurant + $external->sum(fn ($item) => max(0, (float) $item->getAttribute('total') - (float) $item->getAttribute('collections_sum_amount'))), 2);
    }

    /** @return array{restaurant: Tenders, collections: list<array{external_order_id: int, method: string, amount: float}>} */
    public function split(Order $order, Tenders $tenders): array
    {
        $due = (int) round($this->due($order) * 100);
        $tenderTotal = (int) round($tenders->total() * 100);
        if ($tenderTotal < $due || ($tenders->count() > 1 && $tenderTotal > $due)) {
            throw new OrderException('المبلغ المدفوع لا يطابق المطلوب من العميل');
        }

        $amounts = array_map(fn (float $amount): int => (int) round($amount * 100), $tenders->amounts());
        if ($tenders->count() === 1 && $tenderTotal > $due) {
            $key = array_key_first($amounts);
            $amounts[$key] = $due;
        }

        $collections = [];
        $external = $order->externalOrders()->where('status', '!=', 'cancelled')->orderBy('id')->withSum('collections', 'amount')->get();
        foreach ($external as $item) {
            $remaining = max(0, (int) round(((float) $item->getAttribute('total') - (float) $item->getAttribute('collections_sum_amount')) * 100));
            foreach (['cash', 'card', 'talabat_card'] as $method) {
                $amount = min($remaining, $amounts[$method] ?? 0);
                if ($amount > 0) {
                    $collections[] = ['external_order_id' => (int) $item->getKey(), 'method' => $method, 'amount' => (float) ($amount / 100)];
                    $amounts[$method] -= $amount;
                    $remaining -= $amount;
                }
            }
        }

        return ['restaurant' => new Tenders(array_map(fn (int $amount): float => $amount / 100, $amounts)), 'collections' => $collections];
    }
}
