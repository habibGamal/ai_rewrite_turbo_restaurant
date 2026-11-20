<?php

namespace App\Core\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderTypeRow;
use App\Modules\Module;
use App\Orders\OrderBehaviorRegistry;
use Illuminate\Support\Facades\Schema;

/**
 * Refuses to switch a module off while an order of one of its order types
 * is still open: its tab would disappear and the shift could never end.
 */
final class ModuleOpenOrdersBlocker
{
    public function __construct(private readonly Module $module) {}

    /**
     * @return list<string>
     */
    public function __invoke(): array
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('order_types')) {
            return [];
        }

        $registry = app(OrderBehaviorRegistry::class);
        $codes = OrderTypeRow::query()->get(['code', 'behavior'])
            ->filter(fn (OrderTypeRow $type): bool => $type->behavior !== null && $registry->moduleFor($type->behavior) === $this->module)
            ->pluck('code')
            ->all();

        if ($codes === []) {
            return [];
        }

        $count = Order::query()
            ->whereIn('type', $codes)
            ->whereIn('status', [OrderStatus::PENDING, OrderStatus::PROCESSING, OrderStatus::OUT_FOR_DELIVERY])
            ->count();

        return $count > 0
            ? ["لا يمكن إيقاف «{$this->module->label()}» لوجود {$count} طلب مفتوح من أنواعه. أنهِ هذه الطلبات أو ألغِها أولاً."]
            : [];
    }
}
