<?php

namespace App\Services\Reports;

use App\Enums\OrderStatus;
use App\Models\Equipment;
use App\Models\OrderEquipment;
use App\Services\FeatureService;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Equipment usage of completed orders, scoped to a set of shifts (the
 * order's shift, like sales). Used by «تقرير المعدات» and the shift reports.
 * Refunds of equipment lines count in the shift that paid them, like every
 * other return; equipment cost stays (it was consumed).
 */
class EquipmentReportService
{
    /**
     * Restrict a query on order_equipment (or a relation to it) to completed
     * orders of the given shifts and, optionally, one order type.
     *
     * @param  QueryBuilder|array<int|string>  $shiftIds
     */
    public function scopeLines(EloquentBuilder $lines, QueryBuilder|array $shiftIds, ?string $orderType = null): EloquentBuilder
    {
        return $lines->whereHas('order', fn ($orders) => $orders
            ->where('status', OrderStatus::COMPLETED)
            ->whereIn('shift_id', $shiftIds instanceof QueryBuilder ? clone $shiftIds : $shiftIds)
            ->when($orderType, fn ($query, $type) => $query->where('type', $type)));
    }

    /**
     * Equipment rows with uses, revenue and cost in the scope.
     *
     * @param  QueryBuilder|array<int|string>  $shiftIds
     */
    public function perEquipmentQuery(QueryBuilder|array $shiftIds, ?string $orderType = null): Builder
    {
        $scope = fn ($lines) => $this->scopeLines($lines, $shiftIds, $orderType);

        $refunds = fn (string $column) => $this->refundLines($shiftIds, $orderType)
            ->whereColumn('reqp.equipment_id', 'equipment.id')
            ->selectRaw("COALESCE(SUM(ori.{$column}), 0)");

        return Equipment::query()
            ->withCount(['orderLines as orders_count' => $scope])
            ->withSum(['orderLines as uses' => $scope], 'quantity')
            ->withSum(['orderLines as revenue' => $scope], 'total')
            ->withSum(['orderLines as cost' => $scope], 'cost_total')
            ->selectSub($refunds('quantity'), 'refunded_uses')
            ->selectSub($refunds('refund_amount'), 'refunded');
    }

    /**
     * Refunded equipment lines (order_return_items as ori, joined to
     * order_equipment as reqp) of returns paid in the scope's shifts.
     *
     * @param  QueryBuilder|array<int|string>  $shiftIds
     */
    public function refundLines(QueryBuilder|array $shiftIds, ?string $orderType = null): QueryBuilder
    {
        return DB::table('order_return_items as ori')
            ->join('order_returns as orr', 'orr.id', '=', 'ori.order_return_id')
            ->join('order_equipment as reqp', 'reqp.id', '=', 'ori.order_equipment_id')
            ->whereIn('orr.shift_id', $shiftIds instanceof QueryBuilder ? clone $shiftIds : $shiftIds)
            ->when($orderType, fn ($query, $type) => $query
                ->join('orders as ro', 'ro.id', '=', 'reqp.order_id')
                ->where('ro.type', $type));
    }

    /**
     * @param  QueryBuilder|array<int|string>  $shiftIds
     * @return array{uses: int, revenue: float, cost: float, profit: float, refunded_uses: int, refunded: float, net_revenue: float, net_profit: float}
     */
    public function summary(QueryBuilder|array $shiftIds, ?string $orderType = null): array
    {
        $row = $this->scopeLines(OrderEquipment::query(), $shiftIds, $orderType)
            ->toBase()
            ->selectRaw('COALESCE(SUM(quantity), 0) AS uses, COALESCE(SUM(total), 0) AS revenue, COALESCE(SUM(cost_total), 0) AS cost')
            ->first();

        $refunds = $this->refundLines($shiftIds, $orderType)
            ->selectRaw('COALESCE(SUM(ori.quantity), 0) AS uses, COALESCE(SUM(ori.refund_amount), 0) AS amount')
            ->first();

        $revenue = (float) ($row->revenue ?? 0);
        $cost = (float) ($row->cost ?? 0);
        $refunded = round((float) ($refunds->amount ?? 0), 2);

        return [
            'uses' => (int) ($row->uses ?? 0),
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => $revenue - $cost,
            'refunded_uses' => (int) ($refunds->uses ?? 0),
            'refunded' => $refunded,
            'net_revenue' => round($revenue - $refunded, 2),
            'net_profit' => round($revenue - $refunded - $cost, 2),
        ];
    }

    /**
     * The «المعدات» stat for shift reports: shown while the feature is on, or
     * when the scope still holds equipment money.
     *
     * @param  QueryBuilder|array<int|string>  $shiftIds
     * @return list<Stat>
     */
    public function shiftStats(QueryBuilder|array $shiftIds): array
    {
        $summary = $this->summary($shiftIds);

        if (! app(FeatureService::class)->equipmentEnabled() && $summary['uses'] === 0 && $summary['refunded'] <= 0) {
            return [];
        }

        $description = $summary['uses'].' استخدام · تكلفة '.number_format($summary['cost'], 2).' جنيه';
        if ($summary['refunded'] > 0) {
            $description .= ' · مرتجع '.number_format($summary['refunded'], 2).' · صافي '.number_format($summary['net_revenue'], 2).' جنيه';
        }

        return [
            Stat::make('المعدات', number_format($summary['revenue'], 2).' جنيه')
                ->description($description)
                ->descriptionIcon('heroicon-m-wrench-screwdriver')
                ->color('info'),
        ];
    }
}
