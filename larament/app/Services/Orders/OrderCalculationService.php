<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Repositories\OrderRepository;

class OrderCalculationService
{
    private const TAX_RATE = 0.0; // 0% tax rate as per original system

    public function __construct(
        private readonly OrderRepository $orderRepository,
    ) {}

    public function calculateOrderTotals(Order $order): Order
    {
        $order->load(['items', 'customer']);

        // Calculate subtotal
        $subtotal = $order->items->sum(fn ($item) => $item->total);

        // Calculate service charge
        $serviceCharge = $this->calculateServiceCharge($order, $subtotal);

        // Calculate tax
        $tax = $this->calculateTax($subtotal);

        // Apply discount if set
        $discount = $this->calculateDiscount($order, $subtotal);

        // Calculate total
        $total = ceil($subtotal + $serviceCharge + $tax - $discount);

        // Calculate profit
        $profit = $this->calculateProfit($order, $total);

        // Update order
        $this->orderRepository->update($order, [
            'sub_total' => $subtotal,
            'service' => $serviceCharge,
            'tax' => $tax,
            'discount' => $discount,
            'total' => $total,
            'profit' => $profit,
        ]);

        return $order->refresh();
    }

    /**
     * Item discounts replace an order-level discount.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function clearOrderDiscountForItemDiscounts(Order $order, array $lines): void
    {
        $hasItemDiscounts = collect($lines)->some(fn ($item) => ($item['item_discount'] ?? 0) > 0);

        if ($hasItemDiscounts && ($order->discount > 0 || $order->temp_discount_percent > 0)) {
            $this->orderRepository->update($order, [
                'discount' => 0,
                'temp_discount_percent' => 0,
            ]);
        }
    }

    public function applyDiscount(Order $order, float $discount, string $discountType): Order
    {
        $order->items()->update([
            'item_discount' => 0,
            'item_discount_type' => null,
            'item_discount_percent' => null,
        ]);

        // Reset existing discounts
        $updateData = [
            'discount' => 0,
            'temp_discount_percent' => 0,
        ];

        // Apply new discount
        if ($discountType === 'percent') {
            $updateData['temp_discount_percent'] = $discount;
        } else {
            $updateData['discount'] = $discount;
        }

        $this->orderRepository->update($order, $updateData);
        $order->refresh();

        // Recalculate totals
        return $this->calculateOrderTotals($order);
    }

    private function calculateServiceCharge(Order $order, float $subtotal): float
    {
        if ($order->type->requiresTable()) {
            // Dine-in service charge
            return $subtotal * $order->service_rate;
        } elseif ($order->type->hasDeliveryFee() && $order->customer) {
            // Delivery fee from customer
            return $order->customer->delivery_cost ?? 0;
        }

        return 0;
    }

    private function calculateTax(float $subtotal): float
    {
        return $subtotal * self::TAX_RATE;
    }

    private function calculateDiscount(Order $order, float $subtotal): float
    {
        if ($order->items->contains('item_discount', '>', 0)) {
            return (float) $order->items->sum('item_discount');
        }

        // Use order-level discount if no item discounts
        if ($order->temp_discount_percent > 0) {
            return ($order->temp_discount_percent / 100) * $subtotal;
        }

        return $order->discount ?? 0;
    }

    private function calculateProfit(Order $order, float $total): float
    {
        $totalCost = $order->items->sum(fn ($item) => $item->cost * $item->quantity);

        return $total - $totalCost;
    }
}
