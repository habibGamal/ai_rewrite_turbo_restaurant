<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItemChange;
use App\Models\Product;

class OrderItemChangeService
{
    /**
     * Record item changes to the database for kitchen display and cashier commission tracking.
     *
     * Only records: added, removed, quantity_changed
     * Skips: notes_changed (not relevant for kitchen)
     *
     * @param  int  $orderId  The order that was modified
     * @param  array  $differences  Output from ShiftLoggingService::calculateItemDifferences()
     * @param  int|null  $userId  The user performing the change
     */
    public function recordChanges(int $orderId, array $differences, ?int $userId = null): void
    {
        $userId = $userId ?? auth()->id() ?? Order::where('id', $orderId)->value('user_id');

        foreach ($differences as $diff) {
            // Skip notes changes — not actionable for kitchen
            if ($diff['type'] === 'notes_changed') {
                continue;
            }

            $unitPrice = (float) ($diff['unit_price'] ?? 0);
            if ($unitPrice <= 0 && ! empty($diff['product_id'])) {
                $unitPrice = (float) (Product::where('id', $diff['product_id'])->value('price') ?? 0);
            }

            $changeData = [
                'order_id' => $orderId,
                'user_id' => $userId,
                'product_id' => $diff['product_id'] ?? null,
                'product_name' => $diff['product_name'],
                'change_type' => $diff['type'],
                'unit_price' => $unitPrice,
            ];

            switch ($diff['type']) {
                case 'added':
                    $changeData['new_quantity'] = $diff['quantity'] ?? 0;
                    $changeData['old_quantity'] = 0;
                    $changeData['delta'] = $diff['quantity'] ?? 0;
                    break;
                case 'removed':
                    $changeData['old_quantity'] = $diff['quantity'] ?? 0;
                    $changeData['new_quantity'] = 0;
                    $changeData['delta'] = -($diff['quantity'] ?? 0);
                    break;
                case 'quantity_changed':
                    $changeData['old_quantity'] = $diff['old_quantity'];
                    $changeData['new_quantity'] = $diff['new_quantity'];
                    $changeData['delta'] = $diff['difference'];
                    break;
            }

            $changeData['total_price'] = abs((float) ($changeData['delta'] ?? 0)) * $unitPrice;

            if (empty($changeData['product_id'])) {
                $changeData['product_id'] = null;
            }

            OrderItemChange::create($changeData);
        }
    }
}
