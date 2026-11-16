<?php

namespace App\Services\Resources;

use App\Models\Product;

class StockIssueCalculatorService
{
    /**
     * Calculate total for a single stock issue item
     */
    public static function calculateItemTotal(float $quantity, float $unitCost): float
    {
        return round($quantity * $unitCost, 2);
    }

    /**
     * Calculate total for all items from array
     */
    private static function calculateTotalFromArray(array $items): float
    {
        $total = 0.0;

        foreach ($items as $item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            $productId = (int) ($item['product_id'] ?? 0);

            $cost = null;
            if (isset($item['unit_cost']) && $item['unit_cost'] !== '') {
                $cost = (float) $item['unit_cost'];
            } elseif (isset($item['price']) && $item['price'] !== '') {
                $cost = (float) $item['price'];
            } elseif ($productId > 0) {
                $cost = (float) (Product::whereKey($productId)->value('cost') ?? 0);
            } else {
                $cost = 0.0;
            }

            $total += self::calculateItemTotal($quantity, $cost);
        }

        return round($total, 2);
    }

    /**
     * Calculate total from collection
     */
    private static function calculateTotalFromCollection($items): float
    {
        $total = 0.0;

        foreach ($items as $item) {
            $quantity = (float) ($item->quantity ?? 0);
            $cost = (float) ($item->unit_cost ?? ($item->price ?? ($item->product->cost ?? 0)));
            $total += self::calculateItemTotal($quantity, $cost);
        }

        return round($total, 2);
    }

    /**
     * Calculate total for all items in a stock issue
     */
    public static function calculateTotal($items): float
    {
        if (is_array($items)) {
            return self::calculateTotalFromArray($items);
        }

        return self::calculateTotalFromCollection($items);
    }

    /**
     * Prepare item data with calculated total and unit cost
     */
    public static function prepareItemData(array $data): array
    {
        $quantity = (float) ($data['quantity'] ?? 0);
        $productId = (int) ($data['product_id'] ?? 0);

        $cost = null;
        if (isset($data['unit_cost']) && $data['unit_cost'] !== '') {
            $cost = (float) $data['unit_cost'];
        } elseif (isset($data['price']) && $data['price'] !== '') {
            $cost = (float) $data['price'];
        } elseif ($productId > 0) {
            $cost = (float) (Product::whereKey($productId)->value('cost') ?? 0);
        } else {
            $cost = 0.0;
        }

        $data['product_id'] = $productId;
        $data['quantity'] = $quantity;
        $data['unit_cost'] = $cost;
        $data['total_cost'] = self::calculateItemTotal($quantity, $cost);

        unset(
            $data['product_name'],
            $data['unit'],
            $data['stock_quantity'],
            $data['price'],
            $data['total']
        );

        return $data;
    }

    /**
     * Generate JavaScript code for frontend total calculation
     */
    public static function getJavaScriptCalculation(): string
    {
        return <<<'JS'
            $watch('$wire.data', value => {
                let items = $wire.data.items;
                if (!items) return;
                if (!Array.isArray(items)) {
                    items = Object.values(items);
                }
                let grandTotal = 0;
                items.forEach(item => {
                    const qty = parseFloat(item.quantity) || 0;
                    const cost = parseFloat(item.unit_cost ?? item.price) || 0;
                    const lineTotal = +(qty * cost).toFixed(2);
                    item.total_cost = lineTotal;
                    item.total = lineTotal;
                    grandTotal += lineTotal;
                });
                $wire.data.total_cost = +grandTotal.toFixed(2);
                $wire.data.total = +grandTotal.toFixed(2);
            })
        JS;
    }
}
