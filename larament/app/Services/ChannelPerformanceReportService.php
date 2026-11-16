<?php

namespace App\Services;

use App\Core\Orders\GuestStats;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Reports\Channels\ChannelReportFilters;
use App\Services\Reports\ReportOrderTypesService;
use App\Services\Reports\ReturnsAggregates;
use App\Services\Reports\SqlDates;
use Illuminate\Database\Eloquent\Builder;

/**
 * «تقرير أداء قنوات البيع والمقارنة». A channel is an order type.
 *
 * Every figure comes from the orders themselves (the same split as
 * SalesBreakdown), so the report's totals match the shift and daily reports:
 * net sales = orders.total − refunds paid in the period (grouped by the
 * original order's channel); profit = orders.profit − the refunds' profit
 * impact. Only completed orders count as sales; cancelled orders feed the
 * cancellation rate.
 */
class ChannelPerformanceReportService
{
    /** Distinct colours for channels in charts, by catalog order. */
    public const PALETTE = ['#2563eb', '#f97316', '#10b981', '#a855f7', '#ef4444', '#eab308', '#06b6d4', '#ec4899', '#64748b', '#84cc16'];

    public function __construct(
        private readonly ReportOrderTypesService $types,
        private readonly ReturnsAggregates $returns,
    ) {}

    /**
     * Orders in the filters' period and channels.
     *
     * @return Builder<Order>
     */
    public function ordersQuery(ChannelReportFilters $filters, OrderStatus $status = OrderStatus::COMPLETED): Builder
    {
        return Order::query()
            ->where('orders.status', $status)
            ->where('orders.created_at', '>=', $filters->start->startOfDay())
            ->where('orders.created_at', '<=', $filters->end->endOfDay())
            ->when($filters->channels !== [], fn (Builder $query) => $query->whereIn('orders.type', $filters->channels));
    }

    public function hasOrders(ChannelReportFilters $filters): bool
    {
        return $this->ordersQuery($filters)->exists();
    }

    /**
     * Every channel the report can show, in the cashier's tab order: active
     * ones plus any inactive one that has orders.
     *
     * @return array<string, array{label: string, color: string, chart: string}>
     */
    public function channels(): array
    {
        $result = [];
        foreach ($this->types->forQuery(Order::query()) as $i => $type) {
            $result[$type['code']] = [
                'label' => $type['label'],
                'color' => $type['color'],
                'chart' => self::PALETTE[$i % count(self::PALETTE)],
            ];
        }

        return $result;
    }

    /**
     * @return array<string, string> code => label, for the channels filter
     */
    public function channelOptions(): array
    {
        return array_map(fn (array $channel) => $channel['label'], $this->channels());
    }

    /**
     * One row per channel with sales, cancellations or refunds in the period,
     * biggest net sales first.
     *
     * @return array<string, array<string, mixed>>
     */
    public function channelRows(ChannelReportFilters $filters): array
    {
        $sales = $this->ordersQuery($filters)
            ->toBase()
            ->select('orders.type')
            ->selectRaw('COUNT(*) AS orders_count')
            ->selectRaw('COALESCE(SUM(orders.sub_total), 0) AS items')
            ->selectRaw('COALESCE(SUM(orders.discount), 0) AS discount')
            ->selectRaw('COALESCE(SUM(orders.service), 0) AS service')
            ->selectRaw('COALESCE(SUM(orders.tax), 0) AS tax')
            ->selectRaw('COALESCE(SUM(orders.fees_total), 0) AS fees')
            ->selectRaw('COALESCE(SUM(orders.equipment_total), 0) AS equipment')
            ->selectRaw('COALESCE(SUM(orders.total), 0) AS gross')
            ->selectRaw('COALESCE(SUM(orders.profit), 0) AS profit')
            ->selectRaw('COUNT(DISTINCT orders.customer_id) AS customers')
            ->selectRaw('COALESCE(SUM(CASE WHEN orders.guests_count > 0 THEN orders.guests_count ELSE 0 END), 0) AS guests')
            ->selectRaw('COALESCE(SUM(CASE WHEN orders.guests_count > 0 THEN orders.total ELSE 0 END), 0) AS guest_sales')
            ->selectRaw('COALESCE(SUM(CASE WHEN orders.guests_count > 0 THEN 1 ELSE 0 END), 0) AS guest_orders')
            ->groupBy('orders.type')
            ->get()
            ->keyBy(fn ($row) => (string) $row->type);

        $cancelled = $this->ordersQuery($filters, OrderStatus::CANCELLED)
            ->toBase()
            ->select('orders.type')
            ->selectRaw('COUNT(*) AS cancelled')
            ->groupBy('orders.type')
            ->pluck('cancelled', 'type')
            ->all();

        $returns = $this->returns->groupedBy(
            $this->returns->periodQuery($filters->startDate(), $filters->endDate()),
            'ro.type',
        );
        if ($filters->channels !== []) {
            $returns = array_intersect_key($returns, array_flip($filters->channels));
        }

        $channels = $this->channels();
        $codes = array_unique([...$sales->keys()->all(), ...array_keys($cancelled), ...array_keys($returns)]);

        $rows = [];
        foreach ($codes as $code) {
            $code = (string) $code;
            $row = $sales->get($code);
            $refund = $returns[$code] ?? ['amount' => 0.0, 'profit_impact' => 0.0, 'count' => 0];
            $rows[$code] = $this->derive([
                'code' => $code,
                'label' => $channels[$code]['label'] ?? $this->types->label($code),
                'color' => $channels[$code]['color'] ?? 'gray',
                'chart' => $channels[$code]['chart'] ?? self::PALETTE[count($rows) % count(self::PALETTE)],
                'orders' => (int) ($row->orders_count ?? 0),
                'items' => (float) ($row->items ?? 0),
                'discount' => (float) ($row->discount ?? 0),
                'service' => (float) ($row->service ?? 0),
                'tax' => (float) ($row->tax ?? 0),
                'fees' => (float) ($row->fees ?? 0),
                'equipment' => (float) ($row->equipment ?? 0),
                'gross' => (float) ($row->gross ?? 0),
                'profit' => (float) ($row->profit ?? 0),
                'customers' => (int) ($row->customers ?? 0),
                'guests' => (int) ($row->guests ?? 0),
                'guest_sales' => (float) ($row->guest_sales ?? 0),
                'guest_orders' => (int) ($row->guest_orders ?? 0),
                'cancelled' => (int) ($cancelled[$code] ?? 0),
                'refunds' => (float) $refund['amount'],
                'refunds_count' => (int) $refund['count'],
                'refunds_profit' => (float) $refund['profit_impact'],
            ]);
        }

        uasort($rows, fn (array $a, array $b) => $b['net_sales'] <=> $a['net_sales']);

        $totalSales = array_sum(array_column($rows, 'net_sales'));
        $totalOrders = array_sum(array_column($rows, 'orders'));
        foreach ($rows as $code => $row) {
            $rows[$code]['sales_share'] = self::ratio($row['net_sales'], $totalSales);
            $rows[$code]['orders_share'] = self::ratio($row['orders'], $totalOrders);
        }

        return $rows;
    }

    /**
     * The channels' sums; customers are counted once across channels.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function totals(ChannelReportFilters $filters, array $rows): array
    {
        $sum = fn (string $key) => array_sum(array_column($rows, $key));

        $totals = $this->derive([
            'code' => null,
            'label' => 'الإجمالي',
            'orders' => (int) $sum('orders'),
            'items' => $sum('items'),
            'discount' => $sum('discount'),
            'service' => $sum('service'),
            'tax' => $sum('tax'),
            'fees' => $sum('fees'),
            'equipment' => $sum('equipment'),
            'gross' => $sum('gross'),
            'profit' => $sum('profit'),
            'customers' => (int) $this->ordersQuery($filters)->whereNotNull('orders.customer_id')->distinct()->count('orders.customer_id'),
            'guests' => (int) $sum('guests'),
            'guest_sales' => $sum('guest_sales'),
            'guest_orders' => (int) $sum('guest_orders'),
            'cancelled' => (int) $sum('cancelled'),
            'refunds' => $sum('refunds'),
            'refunds_count' => (int) $sum('refunds_count'),
            'refunds_profit' => $sum('refunds_profit'),
        ]);
        $totals['sales_share'] = $rows === [] ? 0.0 : 100.0;
        $totals['orders_share'] = $rows === [] ? 0.0 : 100.0;

        return $totals;
    }

    /**
     * The whole report: this period's channels and totals, the comparison
     * period's (keyed the same way), and which optional columns have data.
     *
     * @return array{filters: ChannelReportFilters, rows: array<string, array<string, mixed>>, totals: array<string, mixed>, comparison: ?ChannelReportFilters, previous_rows: array<string, array<string, mixed>>, previous_totals: ?array<string, mixed>, columns: array{charges: bool, guests: bool, cancelled: bool, refunds: bool, customers: bool}}
     */
    public function report(ChannelReportFilters $filters): array
    {
        $rows = $this->channelRows($filters);
        $totals = $this->totals($filters, $rows);

        $comparison = $filters->comparison();
        $previousRows = $comparison ? $this->channelRows($comparison) : [];
        $previousTotals = $comparison ? $this->totals($comparison, $previousRows) : null;

        return [
            'filters' => $filters,
            'rows' => $rows,
            'totals' => $totals,
            'comparison' => $comparison,
            'previous_rows' => $previousRows,
            'previous_totals' => $previousTotals,
            'columns' => [
                'charges' => $totals['charges'] != 0.0,
                'guests' => $totals['guests'] > 0,
                'cancelled' => $totals['cancelled'] > 0,
                'refunds' => $totals['refunds'] != 0.0,
                'customers' => $totals['customers'] > 0,
            ],
        ];
    }

    /**
     * Net sales and orders per day, for the KPI sparklines. Refunds count on
     * the day they were paid.
     *
     * @return array<string, array{orders: int, net_sales: float}> keyed by Y-m-d, every day of the period
     */
    public function daily(ChannelReportFilters $filters): array
    {
        $day = SqlDates::day('orders.created_at');
        $sales = $this->ordersQuery($filters)
            ->toBase()
            ->selectRaw("{$day} AS day")
            ->selectRaw('COUNT(*) AS orders_count')
            ->selectRaw('COALESCE(SUM(orders.total), 0) AS gross')
            ->groupByRaw($day)
            ->get()
            ->keyBy('day');

        $returnsQuery = $this->returns->periodQuery($filters->startDate(), $filters->endDate());
        if ($filters->channels !== []) {
            $returnsQuery->whereIn('order_returns.order_id', Order::query()->whereIn('type', $filters->channels)->select('id'));
        }
        $refunds = $this->returns->groupedBy($returnsQuery, SqlDates::day('orr.created_at'));

        $result = [];
        for ($date = $filters->start; $date->lessThanOrEqualTo($filters->end); $date = $date->addDay()) {
            $key = $date->toDateString();
            $row = $sales->get($key);
            $result[$key] = [
                'orders' => (int) ($row->orders_count ?? 0),
                'net_sales' => round((float) ($row->gross ?? 0) - ($refunds[$key]['amount'] ?? 0.0), 2),
            ];
        }

        return $result;
    }

    /**
     * Guests and average per guest over the period's completed orders.
     */
    public function guests(ChannelReportFilters $filters): GuestStats
    {
        return GuestStats::fromQuery($this->ordersQuery($filters));
    }

    /**
     * Add the figures computed from the sums.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function derive(array $row): array
    {
        $row['net_sales'] = round($row['gross'] - $row['refunds'], 2);
        $row['net_profit'] = round($row['profit'] - $row['refunds_profit'], 2);
        $row['charges'] = round($row['service'] + $row['fees'] + $row['equipment'], 2);
        $row['avg_order'] = $row['orders'] > 0 ? round($row['net_sales'] / $row['orders'], 2) : 0.0;
        $row['margin'] = self::ratio($row['net_profit'], $row['net_sales']);
        $row['discount_rate'] = self::ratio($row['discount'], $row['items']);
        $row['refund_rate'] = self::ratio($row['refunds'], $row['gross']);
        $row['cancel_rate'] = self::ratio($row['cancelled'], $row['orders'] + $row['cancelled']);
        $row['avg_per_guest'] = $row['guests'] > 0 ? round($row['guest_sales'] / $row['guests'], 2) : 0.0;

        return $row;
    }

    /** Percentage, 0 when the base is 0. */
    public static function ratio(float|int $part, float|int $whole): float
    {
        return $whole != 0 ? round($part / $whole * 100, 2) : 0.0;
    }

    /** Percentage change, null when there is nothing to compare with. */
    public static function change(float|int $current, float|int|null $previous): ?float
    {
        if ($previous === null || $previous == 0) {
            return null;
        }

        return round(($current - $previous) / abs($previous) * 100, 1);
    }
}
