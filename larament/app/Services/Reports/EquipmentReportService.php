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

/**
 * Equipment usage of completed orders, scoped to a set of shifts (the
 * order's shift, like sales). Used by «تقرير المعدات» and the shift reports.
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

        return Equipment::query()
            ->withCount(['orderLines as orders_count' => $scope])
            ->withSum(['orderLines as uses' => $scope], 'quantity')
            ->withSum(['orderLines as revenue' => $scope], 'total')
            ->withSum(['orderLines as cost' => $scope], 'cost_total');
    }

    /**
     * @param  QueryBuilder|array<int|string>  $shiftIds
     * @return array{uses: int, revenue: float, cost: float, profit: float}
     */
    public function summary(QueryBuilder|array $shiftIds, ?string $orderType = null): array
    {
        $row = $this->scopeLines(OrderEquipment::query(), $shiftIds, $orderType)
            ->toBase()
            ->selectRaw('COALESCE(SUM(quantity), 0) AS uses, COALESCE(SUM(total), 0) AS revenue, COALESCE(SUM(cost_total), 0) AS cost')
            ->first();

        $revenue = (float) ($row->revenue ?? 0);
        $cost = (float) ($row->cost ?? 0);

        return [
            'uses' => (int) ($row->uses ?? 0),
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => $revenue - $cost,
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

        if (! app(FeatureService::class)->equipmentEnabled() && $summary['uses'] === 0) {
            return [];
        }

        return [
            Stat::make('المعدات', number_format($summary['revenue'], 2).' جنيه')
                ->description($summary['uses'].' استخدام · تكلفة '.number_format($summary['cost'], 2).' جنيه')
                ->descriptionIcon('heroicon-m-wrench-screwdriver')
                ->color('info'),
        ];
    }
}
