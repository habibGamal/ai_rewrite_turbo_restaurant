<?php

namespace App\Services\Reports;

use App\Core\Orders\SalesBreakdown;
use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Numbers for «تقرير المصروفات والإيرادات» over a set of shifts.
 *
 * «إيرادات الطلبات» is computed here from completed orders minus the refunds
 * those shifts paid; it is never stored as revenue rows and is not counted
 * anywhere else. Other revenues come from the revenues table by type.
 */
class ExpensesRevenuesReportService
{
    public function __construct(private ReturnsAggregates $returns) {}

    /**
     * @return array{
     *     orders: array{parts: array<string, float>, gross: float, refunds: float, net: float, count: int},
     *     revenue_types: list<array{name: string, count: int, cash: float, card: float, total: float}>,
     *     other_revenues: float,
     *     total_revenues: float,
     *     expenses: float,
     *     net: float,
     * }
     */
    public function summary(QueryBuilder $shiftIds): array
    {
        $orders = Order::query()
            ->where('orders.status', OrderStatus::COMPLETED)
            ->whereIn('orders.shift_id', clone $shiftIds);

        $breakdown = SalesBreakdown::fromQuery($orders);
        $parts = $breakdown->labelled();
        unset($parts['الإجمالي']);

        if (($rounding = $breakdown->rounding()) != 0.0) {
            $parts['تقريب'] = $rounding;
        }

        $refunds = round((float) $this->returns->forShifts(clone $shiftIds)['returnsAmount'], 2);
        $gross = round($breakdown->total, 2);
        $ordersNet = round($gross - $refunds, 2);

        $types = DB::table('revenues')
            ->join('revenue_types', 'revenue_types.id', '=', 'revenues.revenue_type_id')
            ->whereIn('revenues.shift_id', clone $shiftIds)
            ->groupBy('revenue_types.id', 'revenue_types.name', 'revenue_types.sort_order')
            ->orderBy('revenue_types.sort_order')
            ->orderBy('revenue_types.name')
            ->select('revenue_types.name')
            ->selectRaw('COUNT(*) as rows_count')
            ->selectRaw("COALESCE(SUM(CASE WHEN revenues.method = 'cash' THEN revenues.amount ELSE 0 END), 0) as cash_amount")
            ->selectRaw("COALESCE(SUM(CASE WHEN revenues.method = 'card' THEN revenues.amount ELSE 0 END), 0) as card_amount")
            ->get()
            ->map(fn ($row): array => [
                'name' => (string) $row->name,
                'count' => (int) $row->rows_count,
                'cash' => round((float) $row->cash_amount, 2),
                'card' => round((float) $row->card_amount, 2),
                'total' => round((float) $row->cash_amount + (float) $row->card_amount, 2),
            ])
            ->values()
            ->all();

        $other = round(array_sum(array_column($types, 'total')), 2);
        $expenses = round((float) DB::table('expenses')->whereIn('shift_id', clone $shiftIds)->sum('amount'), 2);
        $totalRevenues = round($ordersNet + $other, 2);

        return [
            'orders' => [
                'parts' => array_map(fn ($value) => round((float) $value, 2), $parts),
                'gross' => $gross,
                'refunds' => $refunds,
                'net' => $ordersNet,
                'count' => (clone $orders)->count(),
            ],
            'revenue_types' => $types,
            'other_revenues' => $other,
            'total_revenues' => $totalRevenues,
            'expenses' => $expenses,
            'net' => round($totalRevenues - $expenses, 2),
        ];
    }
}
