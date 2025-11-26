<?php

namespace App\Services\Resources;

class OrderReturnCalculatorService
{
    /**
     * Calculate refund amount for a single return item
     */
    public static function calculateItemRefund(float $quantity, float $unitPrice): float
    {
        return $quantity * $unitPrice;
    }

    /**
     * Calculate total refund from return items
     */
    public static function calculateTotalRefund(array $items): float
    {
        $total = 0;

        foreach ($items as $item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $total += self::calculateItemRefund($quantity, $unitPrice);
        }

        return $total;
    }

    /**
     * Calculate total from refund distribution
     */
    public static function calculateRefundDistributionTotal(array $refunds): float
    {
        $total = 0;

        foreach ($refunds as $refund) {
            $amount = (float) ($refund['amount'] ?? 0);
            $total += $amount;
        }

        return $total;
    }

    /**
     * Prepare return item data with calculated refund amount
     */
    public static function prepareReturnItemData(array $data): array
    {
        $quantity = (float) ($data['quantity'] ?? 0);
        $unitPrice = (float) ($data['unit_price'] ?? 0);

        $data['refund_amount'] = self::calculateItemRefund($quantity, $unitPrice);

        return $data;
    }

    /**
     * Generate JavaScript code for frontend calculation
     */
    public static function getJavaScriptCalculation(): string
    {
        return <<<'JS'
            const updateCalculations = () => {
                let items = $wire.data.return_items;
                if (!Array.isArray(items)) {
                    items = Object.values(items);
                }

                // Calculate refund amount for each item
                items.forEach(item => {
                    const quantity = parseFloat(item.quantity) || 0;
                    const unitPrice = parseFloat(item.unit_price) || 0;
                    const availableQty = parseFloat(item.available_quantity) || 0;

                    // Cap quantity to available
                    if (quantity > availableQty) {
                        item.quantity = availableQty;
                    }

                    item.refund_amount = (item.quantity * unitPrice).toFixed(2);
                });

                // Calculate total refund from items
                const totalRefund = items.reduce((total, item) => {
                    return total + (parseFloat(item.refund_amount) || 0);
                }, 0);

                // Calculate total from refund distribution
                let refunds = $wire.data.refund_distribution;
                if (!Array.isArray(refunds)) {
                    refunds = Object.values(refunds);
                }

                const distributionTotal = refunds.reduce((total, refund) => {
                    return total + (parseFloat(refund.amount) || 0);
                }, 0);

                // Update display fields
                $wire.data.total_refund_display = totalRefund.toFixed(2);
                $wire.data.distribution_total_display = distributionTotal.toFixed(2);

                // Check if distribution matches refund
                const difference = Math.abs(totalRefund - distributionTotal);
                $wire.data.distribution_matches = difference < 0.01;
            };

            $watch('$wire.data', value => {
                updateCalculations();
            });

            updateCalculations();
        JS;
    }
}
