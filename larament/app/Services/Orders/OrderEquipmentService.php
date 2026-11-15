<?php

namespace App\Services\Orders;

use App\Core\Audit\AuditLogger;
use App\Core\Events\OrderChargesChanged;
use App\Enums\OptionalFeature;
use App\Enums\OrderBehavior;
use App\Exceptions\OrderException;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\OrderEquipment;
use App\Models\Shift;
use App\Services\FeatureService;
use App\Sync\DocumentType;
use App\Sync\Outbox;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Equipment lines on an open order. The cashier sends the wanted quantity of
 * each equipment; new lines snapshot the current price and cost, existing
 * lines keep theirs. A line that goes down or away needs
 * order.remove_equipment and is written to the audit log.
 *
 * After every change the order's equipment_total / equipment_cost are
 * re-summed and the totals recalculated (the calculator reads those columns).
 */
class OrderEquipmentService
{
    public const AUDIT_CONTEXT = 'equipment_removed';

    public function __construct(
        private readonly OrderCalculationService $calculator,
    ) {}

    /**
     * @param  array<int|string, int|string>  $quantities  equipment_id => wanted quantity (0 removes)
     */
    public function sync(int $orderId, array $quantities): Order
    {
        return DB::transaction(function () use ($orderId, $quantities): Order {
            app(FeatureService::class)->lockAndAssertEnabled(OptionalFeature::EQUIPMENT);
            $order = $this->lockOpenOrder($orderId);

            /** @var Collection<int, OrderEquipment> $existing */
            $existing = $order->equipmentLines()->lockForUpdate()->get()->keyBy('equipment_id');
            $wanted = $this->normalise($quantities);

            $increases = array_filter($wanted, fn (int $qty, int $id): bool => $qty > ($existing->has($id) ? $existing->get($id)->quantity : 0), ARRAY_FILTER_USE_BOTH);
            $decreases = $existing->filter(fn (OrderEquipment $line): bool => array_key_exists((int) $line->equipment_id, $wanted)
                && $wanted[(int) $line->equipment_id] < $line->quantity);

            $allowed = collect();
            if ($increases !== []) {
                $this->assertCan('order.add_equipment', 'ليس لديك صلاحية إضافة معدات للطلب');
                $allowed = $this->allowedFor($order)->keyBy('id');
                foreach (array_keys($increases) as $equipmentId) {
                    if (! $allowed->has($equipmentId)) {
                        throw new OrderException('هذه المعدة غير متاحة لهذا النوع من الطلبات');
                    }
                }
            }

            if ($decreases->isNotEmpty()) {
                $this->assertCan('order.remove_equipment', 'ليس لديك صلاحية حذف أو تقليل المعدات');
            }

            $shift = $order->shift_id ? Shift::query()->find($order->shift_id) : null;

            foreach ($wanted as $equipmentId => $quantity) {
                $line = $existing->get($equipmentId);

                if ($line === null) {
                    if ($quantity > 0) {
                        $this->createLine($order, $allowed->get($equipmentId), $quantity);
                    }

                    continue;
                }

                if ($quantity === $line->quantity) {
                    continue;
                }

                if ($quantity === 0) {
                    AuditLogger::deleted($line, context: self::AUDIT_CONTEXT, shift: $shift);
                    $line->delete();

                    continue;
                }

                $original = $line->getOriginal();
                $line->forceFill([
                    'quantity' => $quantity,
                    'total' => round($quantity * (float) $line->unit_price, 2),
                    'cost_total' => round($quantity * (float) $line->unit_cost, 2),
                ])->save();

                if ($quantity < (int) $original['quantity']) {
                    AuditLogger::updated($line, $original, context: self::AUDIT_CONTEXT, shift: $shift);
                }
            }

            return $this->recalculate($order);
        });
    }

    public function removeLine(int $orderId, int $lineId): Order
    {
        $line = OrderEquipment::query()->where('order_id', $orderId)->whereKey($lineId)->firstOrFail();

        if ($line->equipment_id === null) {
            // The catalogue row was deleted; drop the line by id.
            return DB::transaction(function () use ($orderId, $lineId): Order {
                app(FeatureService::class)->lockAndAssertEnabled(OptionalFeature::EQUIPMENT);
                $order = $this->lockOpenOrder($orderId);
                $this->assertCan('order.remove_equipment', 'ليس لديك صلاحية حذف أو تقليل المعدات');
                $line = $order->equipmentLines()->whereKey($lineId)->lockForUpdate()->firstOrFail();
                $shift = $order->shift_id ? Shift::query()->find($order->shift_id) : null;
                AuditLogger::deleted($line, context: self::AUDIT_CONTEXT, shift: $shift);
                $line->delete();

                return $this->recalculate($order);
            });
        }

        return $this->sync($orderId, [(int) $line->equipment_id => 0]);
    }

    /**
     * Active equipment the order's type allows, in display order.
     *
     * @return Collection<int, Equipment>
     */
    public function allowedFor(Order $order): Collection
    {
        $type = $order->typeRow();
        if ($type->behavior === OrderBehavior::INSTANT) {
            return collect();
        }

        return $type->equipment()
            ->where('equipment.is_active', true)
            ->orderBy('equipment.sort_order')
            ->orderBy('equipment.name')
            ->get();
    }

    private function createLine(Order $order, Equipment $equipment, int $quantity): void
    {
        $order->equipmentLines()->create([
            'equipment_id' => $equipment->id,
            'name' => $equipment->name,
            'quantity' => $quantity,
            'unit_price' => $equipment->usage_price,
            'unit_cost' => $equipment->usage_cost,
            'total' => round($quantity * (float) $equipment->usage_price, 2),
            'cost_total' => round($quantity * (float) $equipment->usage_cost, 2),
            'user_id' => Auth::id(),
        ]);
    }

    private function recalculate(Order $order): Order
    {
        $order->forceFill([
            'equipment_total' => round((float) $order->equipmentLines()->sum('total'), 2),
            'equipment_cost' => round((float) $order->equipmentLines()->sum('cost_total'), 2),
        ])->save();

        $order = $this->calculator->calculateOrderTotals($order);
        Outbox::record(DocumentType::Order, (string) $order->getAttribute('uuid'));
        OrderChargesChanged::dispatch($order, OrderChargesChanged::EQUIPMENT);

        return $order;
    }

    private function lockOpenOrder(int $orderId): Order
    {
        $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();

        if (! $order->status->canBeModified()) {
            throw new OrderException('لا يمكن تعديل المعدات في هذه المرحلة');
        }

        if ($order->typeRow()->behavior === OrderBehavior::INSTANT) {
            throw new OrderException('لا يمكن إضافة معدات لهذا الطلب');
        }

        return $order;
    }

    private function assertCan(string $permission, string $message): void
    {
        $user = Auth::user();
        if ($user !== null && ! $user->can($permission)) {
            throw new OrderException($message);
        }
    }

    /**
     * @param  array<int|string, int|string>  $quantities
     * @return array<int, int>
     */
    private function normalise(array $quantities): array
    {
        $wanted = [];
        foreach ($quantities as $equipmentId => $quantity) {
            $id = (int) $equipmentId;
            $qty = (int) $quantity;
            if ($id <= 0 || $qty < 0 || $qty > 9999 || (string) $qty !== trim((string) $quantity)) {
                throw new OrderException('بيانات المعدات غير صالحة');
            }
            $wanted[$id] = $qty;
        }

        return $wanted;
    }
}
