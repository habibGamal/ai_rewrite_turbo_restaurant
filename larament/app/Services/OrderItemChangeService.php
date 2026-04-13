<?php

namespace App\Services;

use App\Models\OrderItemChange;

class OrderItemChangeService
{
    /**
     * Record item changes to the database for kitchen display.
     *
     * Only records: added, removed, quantity_changed
     * Skips: notes_changed (not relevant for kitchen)
     *
     * @param  int  $orderId  The order that was modified
     * @param  array  $differences  Output from ShiftLoggingService::calculateItemDifferences()
     */
    public function recordChanges(int $orderId, array $differences): void
    {
        foreach ($differences as $diff) {
            // Skip notes changes — not actionable for kitchen
            if ($diff['type'] === 'notes_changed') {
                continue;
            }

            $changeData = [
                'order_id' => $orderId,
                'product_id' => $diff['product_id'] ?? null,
                'product_name' => $diff['product_name'],
                'change_type' => $diff['type'],
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

            if (empty($changeData['product_id'])) {
                $changeData['product_id'] = null;
            }

            OrderItemChange::create($changeData);
        }
    }
}
