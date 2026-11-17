<?php

namespace App\Orders;

use App\Models\Order;

final class ReceiptView
{
    public static function forOrder(Order $order): array
    {
        $order->loadMissing('items.product');
        $restaurantLines = $order->items->mapWithKeys(function ($item): array {
            $subtotal = round((float) $item->getAttribute('quantity') * (float) $item->getAttribute('price'), 2);
            $discount = (float) ($item->getAttribute('item_discount') ?? 0);
            $product = $item->getRelation('product');

            return [$item->getKey() => [
                'name' => $product?->getAttribute('name'),
                'quantity' => (float) $item->getAttribute('quantity'),
                'price' => (float) $item->getAttribute('price'),
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => round($subtotal - $discount, 2),
            ]];
        })->all();
        $external = $order->externalOrders()->where('status', '!=', 'cancelled')->with('items')->orderBy('id')->get();
        $blocks = $external->map(fn ($entry) => [
            'seller_name' => $entry->getAttribute('seller_name'),
            'total' => (float) $entry->getAttribute('total'),
            'items' => $entry->items->map(fn ($item) => [
                'name' => $item->getAttribute('name'),
                'quantity' => (float) $item->getAttribute('quantity'),
                'price' => (float) $item->getAttribute('price'),
                'total' => (float) $item->getAttribute('total'),
            ])->all(),
        ])->all();
        $total = round((float) $external->sum('total'), 2);

        $equipment = $order->equipmentLines()->get()->map(fn ($line) => [
            'name' => $line->getAttribute('name'),
            'quantity' => (int) $line->getAttribute('quantity'),
            'price' => (float) $line->getAttribute('unit_price'),
            'total' => (float) $line->getAttribute('total'),
        ])->all();

        $fees = $order->fees()->get()->map(fn ($fee) => [
            'name' => $fee->getAttribute('name'),
            'amount' => (float) $fee->getAttribute('amount'),
        ])->all();

        return [
            'equipment_lines' => $equipment,
            'fee_lines' => $fees,
            'restaurant_total' => (float) $order->total,
            'restaurant_lines' => $restaurantLines,
            'external_orders' => $blocks,
            'external_total' => $total,
            'guest_total' => round((float) $order->total + $total, 2),
        ];
    }
}
