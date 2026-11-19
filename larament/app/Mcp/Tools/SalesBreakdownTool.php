<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\PaymentMethod;
use App\Filament\Pages\Reports\ChannelPerformanceReport;
use App\Mcp\Support\Period;
use App\Models\Order;
use App\Services\ChannelPerformanceReportService;
use App\Services\Reports\ReturnsAggregates;
use App\Services\Reports\SqlDates;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('sales_breakdown')]
#[Title('Sales breakdown')]
#[Description(<<<'TEXT'
Splits completed sales by one dimension: channel (order type), day, week, month, hour, weekday or payment method. Rows come with orders, net sales, share of the total and, for channel/day/week/month, a comparison period.
Use for "which channel sells most", "best day this month", "busiest hours", "sales by weekday", "how much was paid by card", trends over time.
Not for the headline totals (use sales_summary) or for items (use product_performance).
Examples: «ما أكثر ساعات اليوم ازدحامًا يوم الجمعة؟», «ما نصيب التوصيل من المبيعات هذا الشهر؟», "Show daily sales for the last 30 days".
TEXT)]
#[IsReadOnly]
class SalesBreakdownTool extends BusinessTool
{
    public const GROUPS = ['channel', 'day', 'week', 'month', 'hour', 'weekday', 'payment_method'];

    private const WEEKDAYS = [1 => 'Sunday', 2 => 'Monday', 3 => 'Tuesday', 4 => 'Wednesday', 5 => 'Thursday', 6 => 'Friday', 7 => 'Saturday'];

    private const MAX_DAY_BUCKETS = 93;

    private readonly ChannelPerformanceReportService $channels;

    private readonly ReturnsAggregates $returns;

    /** Services come from the container when the tool is built without them. */
    public function __construct(?ChannelPerformanceReportService $channels = null, ?ReturnsAggregates $returns = null)
    {
        $this->channels = $channels ?? app(ChannelPerformanceReportService::class);
        $this->returns = $returns ?? app(ReturnsAggregates::class);
    }

    protected function reportPages(): array
    {
        return [ChannelPerformanceReport::class];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'group_by' => $schema->string()->enum(self::GROUPS)->required()
                ->description('The dimension to split by. hour and weekday use the order time in the shop timezone.'),
            ...$this->periodSchema($schema),
            'order_types' => $schema->array()->items($schema->string())
                ->description('Only these channels (order type codes). Default: all.'),
            'weekday' => $schema->string()->enum(array_values(self::WEEKDAYS))
                ->description('With group_by=hour: only this weekday.'),
        ];
    }

    protected function run(Request $request): array
    {
        $groupBy = (string) $request->get('group_by');
        if (! in_array($groupBy, self::GROUPS, true)) {
            throw new InvalidArgumentException('group_by must be one of: '.implode(', ', self::GROUPS).'.');
        }

        $period = $this->period($request);
        $orderTypes = array_values(array_map('strval', (array) ($request->get('order_types') ?? [])));
        $filters = $period->channelFilters($orderTypes);
        $comparison = $filters->comparison();

        [$rows, $previous] = match ($groupBy) {
            'channel' => $this->byChannel($period, $orderTypes),
            'day', 'week', 'month' => $this->byTime($period, $orderTypes, $groupBy),
            'hour' => [$this->byHour($period, $orderTypes, $request->get('weekday')), null],
            'weekday' => [$this->byWeekday($period, $orderTypes), null],
            'payment_method' => [$this->byPayment($period, $orderTypes), null],
        };

        $definitions = [
            'net_sales' => 'Completed order totals minus refunds paid in that bucket.',
            'share' => 'Percent of the period total.',
        ];
        if (in_array($groupBy, ['hour', 'weekday', 'payment_method'], true)) {
            $definitions = [
                'sales' => $groupBy === 'payment_method' ? 'Amount paid with this method on completed orders.' : 'Completed order totals (before refunds).',
                'share' => 'Percent of the period total.',
            ];
        }
        if ($groupBy === 'weekday') {
            $definitions['avg_sales_per_day'] = 'sales / number of that weekday in the period.';
        }
        if ($previous !== null) {
            $definitions['change_percent'] = 'Percent change vs the same row in the comparison period (null = nothing to compare).';
        }

        return $this->envelope(
            $period,
            ['group_by' => $groupBy, 'rows' => $rows],
            $definitions,
            [],
            array_filter(['order_types' => $orderTypes ?: null, 'weekday' => $request->get('weekday')]),
            $previous !== null && $comparison !== null ? ['from' => $comparison->startDate(), 'to' => $comparison->endDate()] : null,
        );
    }

    /**
     * @param  list<string>  $orderTypes
     * @return array{0: list<array<string, mixed>>, 1: ?bool}
     */
    private function byChannel(Period $period, array $orderTypes): array
    {
        $report = $this->channels->report($period->channelFilters($orderTypes));
        $previousRows = $report['previous_rows'];

        $rows = [];
        foreach ($report['rows'] as $code => $row) {
            $prev = $previousRows[$code] ?? null;
            $rows[] = array_filter([
                'channel' => $code,
                'label' => $row['label'],
                'orders' => $row['orders'],
                'net_sales' => $row['net_sales'],
                'share' => $row['sales_share'],
                'orders_share' => $row['orders_share'],
                'avg_order' => $row['avg_order'],
                'net_profit' => $row['net_profit'],
                'margin' => $row['margin'],
                'cancelled' => $row['cancelled'],
                'refunds' => $row['refunds'],
                'change_percent' => $report['comparison'] !== null ? [
                    'net_sales' => self::change($row['net_sales'], $prev['net_sales'] ?? null),
                    'orders' => self::change($row['orders'], $prev['orders'] ?? null),
                ] : null,
            ], fn ($value) => $value !== null);
        }

        return [$rows, $report['comparison'] !== null ? true : null];
    }

    /**
     * @param  list<string>  $orderTypes
     * @return array{0: list<array<string, mixed>>, 1: ?bool}
     */
    private function byTime(Period $period, array $orderTypes, string $granularity): array
    {
        if ($granularity === 'day' && $period->days() > self::MAX_DAY_BUCKETS) {
            throw new InvalidArgumentException('Daily rows are limited to '.self::MAX_DAY_BUCKETS.' days. Use group_by=week or month for longer periods.');
        }

        $filters = $period->channelFilters($orderTypes);
        $current = $this->timeSeries($filters->startDate(), $filters->endDate(), $orderTypes, $granularity);
        $comparison = $filters->comparison();
        $previous = $comparison !== null
            ? array_values($this->timeSeries($comparison->startDate(), $comparison->endDate(), $orderTypes, $granularity))
            : null;

        $total = array_sum(array_column($current, 'net_sales'));
        $rows = [];
        foreach (array_values($current) as $i => $row) {
            $row['share'] = self::share($row['net_sales'], $total);
            if ($previous !== null) {
                $prev = $previous[$i] ?? null;
                $row['previous'] = $prev !== null ? ['start' => $prev['start'], 'net_sales' => $prev['net_sales'], 'orders' => $prev['orders']] : null;
                $row['change_percent'] = ['net_sales' => self::change($row['net_sales'], $prev['net_sales'] ?? null)];
            }
            $rows[] = $row;
        }

        return [$rows, $previous !== null ? true : null];
    }

    /**
     * Net sales and orders per bucket, every bucket of the period present.
     *
     * @param  list<string>  $orderTypes
     * @return array<string, array{start: string, orders: int, net_sales: float}>
     */
    private function timeSeries(string $from, string $to, array $orderTypes, string $granularity): array
    {
        $filters = Period::fromArguments(['from' => $from, 'to' => $to, 'compare_to' => 'none'])->channelFilters($orderTypes);
        $bucket = SqlDates::bucket('orders.created_at', $granularity);

        $sales = $this->channels->ordersQuery($filters)
            ->toBase()
            ->selectRaw("{$bucket} AS bucket")
            ->selectRaw('COUNT(*) AS orders_count')
            ->selectRaw('COALESCE(SUM(orders.total), 0) AS gross')
            ->groupByRaw($bucket)
            ->get()
            ->keyBy(fn ($row) => substr((string) $row->bucket, 0, 10));

        $returns = $this->returns->periodQuery($from, $to);
        if ($orderTypes !== []) {
            $returns->whereIn('order_returns.order_id', Order::query()->whereIn('type', $orderTypes)->select('id'));
        }
        $refunds = $this->returns->groupedBy($returns, SqlDates::bucket('orr.created_at', $granularity));

        $cursor = match ($granularity) {
            'week' => $filters->start->subDays(($filters->start->dayOfWeek + 1) % 7),
            'month' => $filters->start->startOfMonth(),
            default => $filters->start,
        };

        $series = [];
        while ($cursor->lessThanOrEqualTo($filters->end)) {
            $key = $cursor->toDateString();
            $row = $sales->get($key);
            $series[$key] = [
                'start' => $key,
                'orders' => (int) ($row->orders_count ?? 0),
                'net_sales' => round((float) ($row->gross ?? 0) - ($refunds[$key]['amount'] ?? 0.0), 2),
            ];
            $cursor = match ($granularity) {
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };
        }

        return $series;
    }

    /**
     * @param  list<string>  $orderTypes
     * @return list<array<string, mixed>>
     */
    private function byHour(Period $period, array $orderTypes, mixed $weekday): array
    {
        $grid = $this->grid($period, $orderTypes);
        $weekdayNumber = $weekday !== null ? array_search($weekday, self::WEEKDAYS, true) : false;

        $hours = array_fill(0, 24, ['orders' => 0, 'sales' => 0.0]);
        foreach ($grid as $day => $cells) {
            if ($weekdayNumber !== false && $day !== $weekdayNumber) {
                continue;
            }
            foreach ($cells as $hour => $cell) {
                $hours[$hour]['orders'] += $cell['orders'];
                $hours[$hour]['sales'] += $cell['sales'];
            }
        }

        $total = array_sum(array_column($hours, 'sales'));
        $rows = [];
        foreach ($hours as $hour => $cell) {
            if ($cell['orders'] === 0) {
                continue;
            }
            $rows[] = [
                'hour' => sprintf('%02d:00', $hour),
                'orders' => $cell['orders'],
                'sales' => round($cell['sales'], 2),
                'share' => self::share($cell['sales'], $total),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $orderTypes
     * @return list<array<string, mixed>>
     */
    private function byWeekday(Period $period, array $orderTypes): array
    {
        $grid = $this->grid($period, $orderTypes);

        $counts = array_fill(1, 7, 0);
        for ($date = $period->from; $date->lessThanOrEqualTo($period->to); $date = $date->addDay()) {
            $counts[$date->dayOfWeek + 1]++;
        }

        $totals = [];
        foreach ($grid as $day => $cells) {
            $totals[$day] = [
                'orders' => array_sum(array_column($cells, 'orders')),
                'sales' => array_sum(array_column($cells, 'sales')),
            ];
        }
        $total = array_sum(array_column($totals, 'sales'));

        $rows = [];
        foreach ([7, 1, 2, 3, 4, 5, 6] as $day) {
            $rows[] = [
                'weekday' => self::WEEKDAYS[$day],
                'days_in_period' => $counts[$day],
                'orders' => $totals[$day]['orders'],
                'sales' => round($totals[$day]['sales'], 2),
                'share' => self::share($totals[$day]['sales'], $total),
                'avg_sales_per_day' => $counts[$day] > 0 ? round($totals[$day]['sales'] / $counts[$day], 2) : 0.0,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $orderTypes
     * @return list<array<string, mixed>>
     */
    private function byPayment(Period $period, array $orderTypes): array
    {
        $methods = [];
        foreach ($this->channels->paymentMix($period->channelFilters($orderTypes)) as $mix) {
            foreach ($mix['methods'] as $method => $amount) {
                $methods[$method] = ($methods[$method] ?? 0.0) + $amount;
            }
        }
        arsort($methods);
        $total = array_sum($methods);

        $rows = [];
        foreach ($methods as $method => $amount) {
            $rows[] = [
                'payment_method' => (string) $method,
                'label' => PaymentMethod::tryFrom((string) $method)?->label() ?? (string) $method,
                'sales' => round($amount, 2),
                'share' => self::share($amount, $total),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $orderTypes
     * @return array<int, array<int, array{orders: int, sales: float}>>
     */
    private function grid(Period $period, array $orderTypes): array
    {
        return $this->channels->heatmap($period->channelFilters($orderTypes));
    }
}
