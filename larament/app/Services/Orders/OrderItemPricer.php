<?php

namespace App\Services\Orders;

use App\DTOs\Orders\OrderItemDTO;
use App\Exceptions\OrderException;
use App\Models\Product;

class OrderItemPricer
{
    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, OrderItemDTO>
     */
    public function price(array $lines): array
    {
        $products = Product::query()
            ->select(['id', 'cost', 'price'])
            ->whereIn('id', array_column($lines, 'product_id'))
            ->get()
            ->keyBy('id');

        $priced = [];

        foreach ($lines as $line) {
            $product = $products->get($line['product_id']);
            if (! $product instanceof Product) {
                throw new OrderException('المنتج غير موجود');
            }

            $priced[] = new OrderItemDTO(
                productId: $product->id,
                quantity: (float) $line['quantity'],
                price: (float) $product->price,
                cost: (float) $product->cost,
                notes: $line['notes'] ?? null,
                itemDiscount: $this->discountFor($product, $line),
                itemDiscountType: $line['item_discount_type'] ?? null,
                itemDiscountPercent: isset($line['item_discount_percent']) ? (float) $line['item_discount_percent'] : null,
            );
        }

        return $priced;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function discountFor(Product $product, array $line): float
    {
        $type = $line['item_discount_type'] ?? null;
        $percent = $line['item_discount_percent'] ?? null;
        $subtotal = (float) $product->price * (float) $line['quantity'];

        if ($type === 'percent' && $percent) {
            return (float) min($subtotal * ($percent / 100), $subtotal);
        }

        if (isset($line['item_discount'])) {
            return (float) min($line['item_discount'], $subtotal);
        }

        return 0.0;
    }
}
