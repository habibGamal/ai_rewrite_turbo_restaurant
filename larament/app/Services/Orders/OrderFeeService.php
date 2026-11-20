<?php

namespace App\Services\Orders;

use App\Core\Audit\AuditLogger;
use App\Core\Events\OrderChargesChanged;
use App\Enums\OptionalFeature;
use App\Enums\OrderBehavior;
use App\Exceptions\OrderException;
use App\Models\Order;
use App\Models\OrderFee;
use App\Models\Shift;
use App\Services\FeatureService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Custom fee lines («رسوم إضافية»): the cashier types a name and an amount.
 * Every change keeps orders.fees_total equal to the sum of the lines, then
 * recalculates the order totals (fees are added after service and discount,
 * which stay on items only) and dispatches OrderChargesChanged.
 */
class OrderFeeService
{
    public const MAX_NAME_LENGTH = 100;

    public function __construct(
        private readonly OrderCalculationService $calculator,
        private readonly FeatureService $features,
    ) {}

    /**
     * @param  list<array{name: string, amount: float|int|string}>  $lines
     */
    public function add(int $orderId, array $lines): Order
    {
        return $this->locked($orderId, function (Order $order) use ($lines): void {
            foreach ($lines as $line) {
                $name = OrderFee::cleanName((string) $line['name']);
                $amount = round((float) $line['amount'], 2);

                if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
                    throw new OrderException('اسم الرسوم مطلوب ولا يزيد عن '.self::MAX_NAME_LENGTH.' حرف');
                }
                if ($amount <= 0) {
                    throw new OrderException('قيمة الرسوم يجب أن تكون أكبر من صفر');
                }

                $order->fees()->create([
                    'name' => $name,
                    'name_key' => OrderFee::keyFor($name),
                    'amount' => $amount,
                    'user_id' => Auth::id(),
                ]);
            }
        });
    }

    /**
     * Remove a saved fee line. Always audited, since the line was part of the bill.
     */
    public function remove(int $orderId, int $feeId, ?string $reason = null): Order
    {
        return $this->locked($orderId, function (Order $order) use ($feeId, $reason): void {
            /** @var OrderFee|null $fee */
            $fee = $order->fees()->whereKey($feeId)->first();
            if (! $fee) {
                throw new OrderException('بند الرسوم غير موجود على هذا الطلب');
            }

            AuditLogger::deleted($fee, $reason, 'order_fee', Shift::query()->find($order->shift_id));
            $fee->delete();
        });
    }

    /**
     * Fee names already used on this install, most used first, for autocomplete.
     *
     * @return list<string>
     */
    public function suggestions(int $limit = 50): array
    {
        // The latest spelling of each grouped name is the one offered.
        $latestIds = OrderFee::query()
            ->selectRaw('MAX(id) as id, COUNT(*) as uses')
            ->groupBy('name_key')
            ->orderByDesc('uses')
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        if ($latestIds === []) {
            return [];
        }

        $names = OrderFee::query()->whereIn('id', $latestIds)->pluck('name', 'id');

        return array_values(array_map(fn ($id) => (string) $names[$id], array_filter($latestIds, fn ($id) => isset($names[$id]))));
    }

    /**
     * Whether an order of this type may carry fees. Instant sale never does.
     */
    public function orderAcceptsFees(Order $order): bool
    {
        return $order->typeRow()->behavior !== OrderBehavior::INSTANT;
    }

    private function locked(int $orderId, callable $change): Order
    {
        return DB::transaction(function () use ($orderId, $change): Order {
            $this->features->assertEnabled(OptionalFeature::CUSTOM_FEES);

            /** @var Order $order */
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();

            if (! $order->status->canBeModified()) {
                throw new OrderException('لا يمكن تعديل الرسوم في هذه المرحلة');
            }
            if (! $this->orderAcceptsFees($order)) {
                throw new OrderException('لا يمكن إضافة رسوم على هذا النوع من الطلبات');
            }

            $change($order);

            $order->forceFill(['fees_total' => round((float) $order->fees()->sum('amount'), 2)])->save();
            $order = $this->calculator->calculateOrderTotals($order);

            OrderChargesChanged::dispatch($order, OrderChargesChanged::FEES);

            return $order;
        });
    }
}
