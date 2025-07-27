<?php

namespace App\Services;

class PurchaseInvoiceCalculatorService
{
    /**
     * Calculate total for a single purchase invoice item
     */
    public static function calculateItemTotal(float $quantity, float $price): float
    {
        return $quantity * $price;
    }

    /**
     * Calculate total for all items in a purchase invoice
     */
    public static function calculateInvoiceTotal(array $items): float
    {
        $total = 0;

        foreach ($items as $item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            $price = (float) ($item['price'] ?? 0);
            $total += self::calculateItemTotal($quantity, $price);
        }

        return $total;
    }

    /**
     * Calculate total from purchase invoice items collection
     */
    public static function calculateInvoiceTotalFromCollection($items): float
    {
        $total = 0;

        foreach ($items as $item) {
            $quantity = (float) ($item->quantity ?? 0);
            $price = (float) ($item->price ?? 0);
            $total += self::calculateItemTotal($quantity, $price);
        }

        return $total;
    }

    /**
     * Prepare item data with calculated total
     */
    public static function prepareItemData(array $data): array
    {
        $quantity = (float) ($data['quantity'] ?? 0);
        $price = (float) ($data['price'] ?? 0);

        $data['total'] = self::calculateItemTotal($quantity, $price);

        return $data;
    }

    /**
     * Generate JavaScript code for frontend total calculation
     */
    public static function getJavaScriptCalculation(): string
    {
        return <<<JS
            let items = \$wire.data.items;
            if (!Array.isArray(items)) {
                items = Object.values(items);
            }
            \$wire.data.total = items.reduce((total, item) => total + (item.quantity * item.price || 0), 0);
        JS;
    }

    /**
     * Generate JavaScript code for individual item total calculation
     */
    public static function getItemJavaScriptCalculation(): string
    {
        return <<<JS
            const splittedId = \$el.getElementsByTagName('input')[0].id.split('.');
            const index = splittedId[splittedId.length - 2];
            \$wire.data.items[index].total = (\$wire.data.items[index].quantity ?? 0) * (\$wire.data.items[index].price ?? 0);
        JS;
    }
}
