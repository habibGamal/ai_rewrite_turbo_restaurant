<?php

namespace App\Services;

use App\Core\Orders\GuestStats;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
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

    /** Day buckets up to 45 days, weeks up to ~6 months, months after that. */
    public static function granularity(ChannelReportFilters $filters): string
    {
        $days = $filters->days();

        return $days <= 45 ? 'day' : ($days <= 186 ? 'week' : 'month');
    }

    /**
     * Net sales and orders per channel per day, week or month, for the trend
     * chart. Every bucket of the period is present for every channel.
     *
     * @return array{granularity: string, buckets: array<string, string>, series: array<string, array{label: string, chart: string, values: array<string, array{orders: int, net_sales: float}>}>}
     */
    public function trend(ChannelReportFilters $filters): array
    {
        $granularity = self::granularity($filters);
        $bucket = SqlDates::bucket('orders.created_at', $granularity);

        $sales = $this->ordersQuery($filters)
            ->toBase()
            ->select('orders.type')
            ->selectRaw("{$bucket} AS bucket")
            ->selectRaw('COUNT(*) AS orders_count')
            ->selectRaw('COALESCE(SUM(orders.total), 0) AS gross')
            ->groupBy('orders.type')
            ->groupByRaw($bucket)
            ->get();

        $refunds = $this->returns->groupedBy(
            $this->returns->periodQuery($filters->startDate(), $filters->endDate()),
            SqlDates::pair('ro.type', SqlDates::bucket('orr.created_at', $granularity)),
        );

        $buckets = $this->buckets($filters, $granularity);
        $channels = $this->channels();
        $empty = array_fill_keys(array_keys($buckets), ['orders' => 0, 'net_sales' => 0.0]);

        $series = [];
        $ensure = function (string $code) use (&$series, $channels, $empty) {
            $series[$code] ??= [
                'label' => $channels[$code]['label'] ?? $this->types->label($code),
                'chart' => $channels[$code]['chart'] ?? self::PALETTE[count($series) % count(self::PALETTE)],
                'values' => $empty,
            ];
        };

        foreach ($sales as $row) {
            $code = (string) $row->type;
            $key = substr((string) $row->bucket, 0, 10);
            if (! isset($empty[$key])) {
                continue;
            }
            $ensure($code);
            $series[$code]['values'][$key]['orders'] += (int) $row->orders_count;
            $series[$code]['values'][$key]['net_sales'] += (float) $row->gross;
        }

        foreach ($refunds as $groupKey => $refund) {
            [$code, $key] = explode('|', (string) $groupKey, 2) + [1 => ''];
            $key = substr($key, 0, 10);
            if (! isset($empty[$key]) || ($filters->channels !== [] && ! in_array($code, $filters->channels, true))) {
                continue;
            }
            $ensure($code);
            $series[$code]['values'][$key]['net_sales'] -= $refund['amount'];
        }

        foreach ($series as $code => $line) {
            foreach ($line['values'] as $key => $value) {
                $series[$code]['values'][$key]['net_sales'] = round($value['net_sales'], 2);
            }
        }

        // Biggest channel first, so it gets the first legend slot.
        uasort($series, fn (array $a, array $b) => array_sum(array_column($b['values'], 'net_sales')) <=> array_sum(array_column($a['values'], 'net_sales')));

        return ['granularity' => $granularity, 'buckets' => $buckets, 'series' => $series];
    }

    /**
     * Orders and sales by weekday (1 = Sunday … 7 = Saturday) and hour, for
     * one channel or all of them.
     *
     * @return array<int, array<int, array{orders: int, sales: float}>> [weekday][hour]
     */
    public function heatmap(ChannelReportFilters $filters, ?string $channel = null): array
    {
        $day = SqlDates::dayOfWeek('orders.created_at');
        $hour = SqlDates::hour('orders.created_at');

        $rows = $this->ordersQuery($filters)
            ->when($channel, fn (Builder $query) => $query->where('orders.type', $channel))
            ->toBase()
            ->selectRaw("{$day} AS weekday")
            ->selectRaw("{$hour} AS hour")
            ->selectRaw('COUNT(*) AS orders_count')
            ->selectRaw('COALESCE(SUM(orders.total), 0) AS sales')
            ->groupByRaw("{$day}, {$hour}")
            ->get();

        $grid = [];
        foreach (range(1, 7) as $weekday) {
            $grid[$weekday] = array_fill(0, 24, ['orders' => 0, 'sales' => 0.0]);
        }
        foreach ($rows as $row) {
            $grid[(int) $row->weekday][(int) $row->hour] = [
                'orders' => (int) $row->orders_count,
                'sales' => round((float) $row->sales, 2),
            ];
        }

        return $grid;
    }

    /**
     * Best-selling products per channel by item value (order_items.total,
     * before the order-level discount), with each one's share of the
     * channel's item value.
     *
     * @return array<string, list<array{product_id: int, name: string, quantity: float, sales: float, share: float}>>
     */
    public function topProducts(ChannelReportFilters $filters, int $limit = 10, string $by = 'sales'): array
    {
        $rows = $this->ordersQuery($filters)
            ->toBase()
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->select('orders.type', 'order_items.product_id', 'products.name')
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) AS quantity')
            ->selectRaw('COALESCE(SUM(order_items.total), 0) AS sales')
            ->groupBy('orders.type', 'order_items.product_id', 'products.name')
            ->get();

        $channelSales = [];
        foreach ($rows as $row) {
            $channelSales[(string) $row->type] = ($channelSales[(string) $row->type] ?? 0) + (float) $row->sales;
        }

        $key = $by === 'quantity' ? 'quantity' : 'sales';
        $result = [];
        foreach ($rows->groupBy('type') as $code => $products) {
            $result[(string) $code] = $products
                ->sortByDesc(fn ($row) => (float) $row->{$key})
                ->take($limit)
                ->map(fn ($row) => [
                    'product_id' => (int) $row->product_id,
                    'name' => (string) $row->name,
                    'quantity' => round((float) $row->quantity, 3),
                    'sales' => round((float) $row->sales, 2),
                    'share' => self::ratio((float) $row->sales, $channelSales[(string) $code]),
                ])
                ->values()
                ->all();
        }

        return $result;
    }

    /**
     * How each channel's completed orders were paid, by payment method.
     *
     * @return array<string, array{total: float, methods: array<string, float>}>
     */
    public function paymentMix(ChannelReportFilters $filters): array
    {
        $rows = $this->ordersQuery($filters)
            ->toBase()
            ->join('payments', 'payments.order_id', '=', 'orders.id')
            ->select('orders.type', 'payments.method')
            ->selectRaw('COALESCE(SUM(payments.amount), 0) AS amount')
            ->groupBy('orders.type', 'payments.method')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $code = (string) $row->type;
            $result[$code] ??= ['total' => 0.0, 'methods' => []];
            $result[$code]['methods'][(string) $row->method] = round((float) $row->amount, 2);
            $result[$code]['total'] = round($result[$code]['total'] + (float) $row->amount, 2);
        }

        // Methods in a fixed order: cash, card, Talabat card, then anything else.
        $order = array_flip(array_map(fn (PaymentMethod $method) => $method->value, PaymentMethod::cases()));
        foreach ($result as $code => $mix) {
            uksort($mix['methods'], fn (string $a, string $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
            $result[$code]['methods'] = $mix['methods'];
        }

        return $result;
    }

    /**
     * @return array<string, string> bucket start (Y-m-d) => label
     */
    private function buckets(ChannelReportFilters $filters, string $granularity): array
    {
        $cursor = match ($granularity) {
            'week' => $filters->start->subDays(($filters->start->dayOfWeek + 1) % 7),
            'month' => $filters->start->startOfMonth(),
            default => $filters->start,
        };

        $buckets = [];
        while ($cursor->lessThanOrEqualTo($filters->end)) {
            $buckets[$cursor->toDateString()] = match ($granularity) {
                'week' => 'أسبوع '.$cursor->format('d/m'),
                'month' => $cursor->format('m/Y'),
                default => $cursor->format('d/m'),
            };
            $cursor = match ($granularity) {
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };
        }

        return $buckets;
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
