<?php

namespace App\Core\Orders;

use App\Models\Order;
use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * How an order's total (or a set of orders' totals) splits into items,
 * service, tax, discount, custom fees and equipment. Every report that shows
 * the split uses this, so they all agree.
 *
 * total = items + service + tax − discount + fees + equipment, rounded up per
 * order; rounding is the difference reports may show as «تقريب».
 */
final class SalesBreakdown
{
    public function __construct(
        public readonly float $items = 0.0,
        public readonly float $service = 0.0,
        public readonly float $tax = 0.0,
        public readonly float $discount = 0.0,
        public readonly float $fees = 0.0,
        public readonly float $equipment = 0.0,
        public readonly float $total = 0.0,
        public readonly float $equipmentCost = 0.0,
    ) {}

    public static function fromOrder(Order $order): self
    {
        return new self(
            items: (float) $order->sub_total,
            service: (float) $order->service,
            tax: (float) $order->tax,
            discount: (float) $order->discount,
            fees: (float) $order->fees_total,
            equipment: (float) $order->equipment_total,
            total: (float) $order->total,
            equipmentCost: (float) $order->equipment_cost,
        );
    }

    /**
     * Sum the split over a query of orders (filter it by status, shift or
     * date first). Columns are qualified so the query may join other tables.
     *
     * @param  EloquentBuilder|QueryBuilder  $orders
     */
    public static function fromQuery($orders): self
    {
        $row = (clone $orders)->toBase()->selectRaw(
            'COALESCE(SUM(orders.sub_total), 0) AS items,'
            .' COALESCE(SUM(orders.service), 0) AS service,'
            .' COALESCE(SUM(orders.tax), 0) AS tax,'
            .' COALESCE(SUM(orders.discount), 0) AS discount,'
            .' COALESCE(SUM(orders.fees_total), 0) AS fees,'
            .' COALESCE(SUM(orders.equipment_total), 0) AS equipment,'
            .' COALESCE(SUM(orders.total), 0) AS total,'
            .' COALESCE(SUM(orders.equipment_cost), 0) AS equipment_cost'
        )->first();

        return new self(
            items: (float) ($row->items ?? 0),
            service: (float) ($row->service ?? 0),
            tax: (float) ($row->tax ?? 0),
            discount: (float) ($row->discount ?? 0),
            fees: (float) ($row->fees ?? 0),
            equipment: (float) ($row->equipment ?? 0),
            total: (float) ($row->total ?? 0),
            equipmentCost: (float) ($row->equipment_cost ?? 0),
        );
    }

    public function plus(self $other): self
    {
        return new self(
            items: $this->items + $other->items,
            service: $this->service + $other->service,
            tax: $this->tax + $other->tax,
            discount: $this->discount + $other->discount,
            fees: $this->fees + $other->fees,
            equipment: $this->equipment + $other->equipment,
            total: $this->total + $other->total,
            equipmentCost: $this->equipmentCost + $other->equipmentCost,
        );
    }

    /**
     * Rounding added by ceil() on each order's total.
     */
    public function rounding(): float
    {
        return round($this->total - ($this->items + $this->service + $this->tax - $this->discount + $this->fees + $this->equipment), 2);
    }

    /**
     * @return array{items: float, service: float, tax: float, discount: float, fees: float, equipment: float, rounding: float, total: float, equipment_cost: float}
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'service' => $this->service,
            'tax' => $this->tax,
            'discount' => $this->discount,
            'fees' => $this->fees,
            'equipment' => $this->equipment,
            'rounding' => $this->rounding(),
            'total' => $this->total,
            'equipment_cost' => $this->equipmentCost,
        ];
    }

    /**
     * Arabic label => amount, for report tables. Fees and equipment rows are
     * left out when they are zero, so reports look the same until they are used.
     *
     * @return array<string, float>
     */
    public function labelled(): array
    {
        $rows = [
            'الأصناف' => $this->items,
            'الخدمة' => $this->service,
            'الضريبة' => $this->tax,
            'الخصم' => -$this->discount,
            'رسوم إضافية' => $this->fees,
            'المعدات' => $this->equipment,
        ];

        if ($this->fees == 0.0) {
            unset($rows['رسوم إضافية']);
        }
        if ($this->equipment == 0.0) {
            unset($rows['المعدات']);
        }
        if ($this->tax == 0.0) {
            unset($rows['الضريبة']);
        }

        return $rows + ['الإجمالي' => $this->total];
    }
}
