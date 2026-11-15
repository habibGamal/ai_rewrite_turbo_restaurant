<?php

namespace App\Core\Equipment;

use App\Core\Contracts\OrderTypeChangeGuard;
use App\Exceptions\OrderException;
use App\Models\Order;
use App\Models\OrderEquipment;
use App\Models\OrderTypeRow;

/**
 * An order holding equipment cannot move to a type that does not allow that
 * equipment; the cashier removes the line first.
 */
final class EquipmentTypeChangeGuard implements OrderTypeChangeGuard
{
    public function assertCanChange(Order $order, OrderTypeRow $newType): void
    {
        $lines = $order->equipmentLines()->get();
        if ($lines->isEmpty()) {
            return;
        }

        $allowed = $newType->equipment()->pluck('equipment.id')->map(fn ($id): int => (int) $id)->all();
        $blocked = $lines->filter(fn (OrderEquipment $line): bool => ! in_array((int) $line->equipment_id, $allowed, true));

        if ($blocked->isNotEmpty()) {
            throw new OrderException('لا يمكن تغيير النوع لأن المعدات ('.$blocked->pluck('name')->implode('، ').') غير متاحة في '.$newType->label.'. احذفها من الطلب أولاً.');
        }
    }
}
