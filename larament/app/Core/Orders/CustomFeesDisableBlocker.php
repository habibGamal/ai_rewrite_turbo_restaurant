<?php

namespace App\Core\Orders;

use App\Enums\OrderStatus;
use App\Models\OrderFee;
use Illuminate\Support\Facades\Schema;

/**
 * Refuses to switch custom fees off while an open order still carries a fee
 * line, so no order is left with a charge the cashier can no longer see.
 */
final class CustomFeesDisableBlocker
{
    /**
     * @return list<string>
     */
    public function __invoke(): array
    {
        if (! Schema::hasTable('order_fees')) {
            return [];
        }

        $count = OrderFee::query()
            ->whereHas('order', fn ($query) => $query->whereIn('status', [
                OrderStatus::PENDING,
                OrderStatus::PROCESSING,
                OrderStatus::OUT_FOR_DELIVERY,
            ]))
            ->distinct()
            ->count('order_id');

        return $count > 0
            ? ["لا يمكن إيقاف ميزة الرسوم الإضافية لوجود {$count} طلب مفتوح عليه رسوم إضافية."]
            : [];
    }
}
