<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Orders\Behaviors\ServiceScalesWithItems;

/**
 * What the customer gets back for returned item lines.
 *
 * A line's refundable value is what the customer actually paid for it:
 *   price × quantity
 *   − its own item discount (touches this line only)
 *   − its share of the order-level discount (split by the lines' value after item discounts)
 *   + its share of a percentage service charge and of tax (split by the lines' gross value)
 *   ± its share of the rounding of the order total (the total is rounded up).
 *
 * A flat service (delivery fee) is not refunded with the items. Custom fees
 * and equipment are refunded by their own return lines, not here.
 *
 * Partial quantities get value × returned / ordered, rounded to 0.01; the
 * return that takes the last units of a line gets whatever is left, so the
 * returns of a line always add up to exactly its refundable value.
 */
class ReturnRefundCalculator
{
    /**
     * The refundable value of every item line of the order, with its parts.
     *
     * @return array<int, array{quantity: float, unit_price: float, gross: float, item_discount: float, order_discount: float, service: float, tax: float, rounding: float, refundable: float, unit_refund: float}>
     */
    public function lines(Order $order): array
    {
        $order->loadMissing('items');

        $items = $order->items->filter(fn ($item) => $item instanceof OrderItem);

        $gross = [];
        $net = [];
        $itemDiscounts = 0.0;
        foreach ($items as $item) {
            $lineGross = (float) $item->price * (float) $item->quantity;
            $lineDiscount = min(max(0.0, (float) $item->item_discount), $lineGross);
            $gross[$item->id] = $lineGross;
            $net[$item->id] = $lineGross - $lineDiscount;
            $itemDiscounts += $lineDiscount;
        }

        $grossSum = array_sum($gross);
        $netSum = array_sum($net);

        // order.discount holds every discount the total took off: item
        // discounts plus any order-level one (fixed amount or percent).
        $orderDiscount = max(0.0, (float) $order->discount - $itemDiscounts);
        $service = $this->serviceRefundsWithItems($order) ? (float) $order->service : 0.0;
        $tax = (float) $order->tax;

        // What the items cost before rounding, and what was actually paid for them.
        $computed = (float) $order->sub_total + (float) $order->service + $tax - (float) $order->discount;
        $paid = (float) $order->total - (float) $order->fees_total - (float) $order->equipment_total;
        $roundingTotal = $computed > 0 ? max(0.0, $paid) - $computed : 0.0;
        // Rounding is shared by what the line paid before rounding, flat service included.
        $flatService = (float) $order->service - $service;

        $lines = [];
        foreach ($items as $item) {
            $quantity = (float) $item->quantity;
            $itemDiscount = $gross[$item->id] - $net[$item->id];
            $orderShare = $netSum > 0 ? $orderDiscount * $net[$item->id] / $netSum : 0.0;
            $serviceShare = $grossSum > 0 ? $service * $gross[$item->id] / $grossSum : 0.0;
            $taxShare = $grossSum > 0 ? $tax * $gross[$item->id] / $grossSum : 0.0;
            $flatShare = $grossSum > 0 ? $flatService * $gross[$item->id] / $grossSum : 0.0;

            $beforeRounding = $net[$item->id] - $orderShare + $serviceShare + $taxShare;
            $rounding = $computed > 0 ? $roundingTotal * ($beforeRounding + $flatShare) / $computed : 0.0;
            $refundable = max(0.0, round($beforeRounding + $rounding, 2));

            $lines[$item->id] = [
                'quantity' => $quantity,
                'unit_price' => (float) $item->price,
                'gross' => round($gross[$item->id], 2),
                'item_discount' => round($itemDiscount, 2),
                'order_discount' => round($orderShare, 2),
                'service' => round($serviceShare, 2),
                'tax' => round($taxShare, 2),
                'rounding' => round($rounding, 2),
                'refundable' => $refundable,
                'unit_refund' => $quantity > 0 ? round($refundable / $quantity, 2) : 0.0,
            ];
        }

        return $lines;
    }

    /**
     * @return array{quantity: float, unit_price: float, gross: float, item_discount: float, order_discount: float, service: float, tax: float, rounding: float, refundable: float, unit_refund: float}|null
     */
    public function line(Order $order, int $orderItemId): ?array
    {
        return $this->lines($order)[$orderItemId] ?? null;
    }

    /**
     * The refund for returning $quantity units of a line, given what earlier
     * returns of the line already took.
     */
    public function refundFor(Order $order, int $orderItemId, float $quantity, float $returnedQuantity = 0.0, float $refundedAmount = 0.0): float
    {
        $line = $this->line($order, $orderItemId);
        if ($line === null || $quantity <= 0 || $line['quantity'] <= 0) {
            return 0.0;
        }

        $left = max(0.0, round($line['refundable'] - $refundedAmount, 2));

        // The last units take whatever is left of the line.
        if ($returnedQuantity + $quantity >= $line['quantity'] - 0.0001) {
            return $left;
        }

        return min($left, round($line['refundable'] * $quantity / $line['quantity'], 2));
    }

    /**
     * One Arabic line that shows how a line's refundable value was reached,
     * e.g. «100.00 × 2 = 200.00 − خصم الصنف 20.00 − نصيبه من خصم الفاتورة 18.00 = 162.00».
     *
     * @param  array{quantity: float, unit_price: float, gross: float, item_discount: float, order_discount: float, service: float, tax: float, rounding: float, refundable: float, unit_refund: float}  $line
     */
    public static function explain(array $line): string
    {
        $money = fn (float $value): string => number_format($value, 2);
        $quantity = rtrim(rtrim(number_format($line['quantity'], 3, '.', ''), '0'), '.');

        $text = $money($line['unit_price']).' × '.$quantity.' = '.$money($line['gross']);
        $adjusted = false;

        foreach ([
            'item_discount' => ['−', 'خصم الصنف'],
            'order_discount' => ['−', 'نصيبه من خصم الفاتورة'],
            'service' => ['+', 'نصيبه من الخدمة'],
            'tax' => ['+', 'نصيبه من الضريبة'],
        ] as $key => [$sign, $label]) {
            if (abs($line[$key]) >= 0.005) {
                $text .= " {$sign} {$label} ".$money($line[$key]);
                $adjusted = true;
            }
        }

        if (abs($line['rounding']) >= 0.005) {
            $text .= ' '.($line['rounding'] > 0 ? '+' : '−').' تقريب '.$money(abs($line['rounding']));
            $adjusted = true;
        }

        if ($adjusted) {
            $text .= ' = '.$money($line['refundable']);
        }

        if ($line['quantity'] > 1) {
            $text .= ' (الوحدة '.$money($line['unit_refund']).')';
        }

        return $text;
    }

    private function serviceRefundsWithItems(Order $order): bool
    {
        if ((float) $order->service <= 0) {
            return false;
        }

        try {
            return $order->behavior() instanceof ServiceScalesWithItems;
        } catch (\Throwable) {
            return false;
        }
    }
}
