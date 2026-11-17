<?php

namespace App\Core\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Support\Facades\Schema;

/**
 * Refuses to switch deferred payment off while customers still owe money:
 * the screens to collect it would disappear with the feature.
 */
final class DeferredPaymentDisableBlocker
{
    /**
     * @return list<string>
     */
    public function __invoke(): array
    {
        if (! Schema::hasTable('orders')) {
            return [];
        }

        $count = Order::query()
            ->where('status', OrderStatus::COMPLETED)
            ->whereIn('payment_status', [PaymentStatus::PENDING, PaymentStatus::PARTIAL_PAID])
            ->count();

        return $count > 0
            ? ["لا يمكن إيقاف الدفع الآجل لوجود {$count} طلب لم يُحصَّل بالكامل. حصّل المتبقي أولاً."]
            : [];
    }
}
