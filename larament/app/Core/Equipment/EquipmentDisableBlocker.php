<?php

namespace App\Core\Equipment;

use App\Enums\OrderStatus;
use App\Models\OrderEquipment;
use Illuminate\Support\Facades\Schema;

/**
 * Refuses to switch equipment off while an open order still carries an
 * equipment line (its money would be charged with no way to see or edit it).
 */
final class EquipmentDisableBlocker
{
    /**
     * @return list<string>
     */
    public function __invoke(): array
    {
        if (! Schema::hasTable('order_equipment')) {
            return [];
        }

        $count = OrderEquipment::query()
            ->whereHas('order', fn ($query) => $query->whereIn('status', [
                OrderStatus::PENDING,
                OrderStatus::PROCESSING,
                OrderStatus::OUT_FOR_DELIVERY,
            ]))
            ->distinct()
            ->count('order_id');

        return $count > 0
            ? ["لا يمكن إيقاف ميزة المعدات لوجود معدات على {$count} طلب مفتوح."]
            : [];
    }
}
