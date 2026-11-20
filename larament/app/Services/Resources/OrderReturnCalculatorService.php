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

                    // Same rule as the server (ReturnRefundCalculator): a share of
                    // what the customer paid for the line; the last units take what is left.
                    const qty = parseFloat(item.quantity) || 0;
                    const original = parseFloat(item.original_quantity) || 0;
                    const refundable = parseFloat(item.line_refundable);
                    const refunded = parseFloat(item.line_refunded) || 0;
                    let amount = qty * unitPrice;
                    if (!isNaN(refundable) && original > 0) {
                        const left = Math.max(0, Math.round((refundable - refunded) * 100) / 100);
                        amount = qty <= 0 ? 0 : (qty >= availableQty - 0.0001
                            ? left
                            : Math.min(left, Math.round(refundable * qty / original * 100) / 100));
                    }
                    item.refund_amount = amount.toFixed(2);
                });

                // Fee (by amount) and equipment (by quantity) lines keep their own
                // refund amounts; the server caps them on blur.
                const rows = (value) => Array.isArray(value) ? value : Object.values(value || {});
                const chargesRefund = [...rows($wire.data.return_fees), ...rows($wire.data.return_equipment)]
                    .reduce((total, line) => total + (parseFloat(line.refund_amount) || 0), 0);

                // Calculate total refund from items, fees and equipment
                const totalRefund = items.reduce((total, item) => {
                    return total + (parseFloat(item.refund_amount) || 0);
                }, 0) + chargesRefund;

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
