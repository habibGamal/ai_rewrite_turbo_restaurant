<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Filament\Pages\Reports\ChannelPerformanceReport;
use App\Services\ChannelPerformanceReportService;
use App\Services\FeatureService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('sales_summary')]
#[Title('Sales summary')]
#[Description(<<<'TEXT'
Headline sales figures for a period, compared with another period: completed orders, net sales, profit and margin, average order, discounts, service/tax/fees, cancellations, refunds and guests.
Use for "how much did we sell", "profit this month vs last month", "average ticket", "how many orders were cancelled".
Not for splitting by day, hour, channel or payment method (use sales_breakdown) or for items (use product_performance).
Examples: «كم بلغت مبيعات هذا الشهر؟», «ما صافي ربح أمس مقارنة باليوم نفسه من الأسبوع الماضي؟», "What is our average order value this week?".
TEXT)]
#[IsReadOnly]
class SalesSummaryTool extends BusinessTool
{
    private const METRICS = [
        'orders', 'net_sales', 'gross', 'items', 'discount', 'service', 'tax', 'fees', 'equipment',
        'net_profit', 'margin', 'avg_order', 'discount_rate', 'cancelled', 'cancel_rate',
        'refunds', 'refunds_count', 'refund_rate', 'customers', 'guests', 'avg_per_guest',
    ];

    private readonly ChannelPerformanceReportService $channels;

    private readonly FeatureService $features;

    /** Services come from the container when the tool is built without them. */
    public function __construct(?ChannelPerformanceReportService $channels = null, ?FeatureService $features = null)
    {
        $this->channels = $channels ?? app(ChannelPerformanceReportService::class);
        $this->features = $features ?? app(FeatureService::class);
    }

    protected function reportPages(): array
    {
        return [ChannelPerformanceReport::class];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->periodSchema($schema),
            'order_types' => $schema->array()->items($schema->string())
                ->description('Only these channels (order type codes from get_business_context, e.g. dine_in, takeaway, delivery). Default: all.'),
        ];
    }

    protected function run(Request $request): array
    {
        $period = $this->period($request);
        $orderTypes = array_values(array_map('strval', (array) ($request->get('order_types') ?? [])));
        $report = $this->channels->report($period->channelFilters($orderTypes));

        $current = $this->pick($report['totals']);
        $previous = $report['previous_totals'] !== null ? $this->pick($report['previous_totals']) : null;

        $change = [];
        if ($previous !== null) {
            foreach (['orders', 'net_sales', 'net_profit', 'avg_order', 'cancelled', 'refunds', 'guests'] as $key) {
                $change[$key] = self::change($current[$key], $previous[$key]);
            }
            foreach (['margin', 'discount_rate', 'cancel_rate'] as $key) {
                $change[$key.'_points'] = round($current[$key] - $previous[$key], 2);
            }
        }

        $caveats = [];
        if ($current['orders'] === 0) {
            $caveats[] = 'No completed orders in this period.';
        }
        if (! $this->features->returnsEnabled()) {
            unset($current['refunds'], $current['refunds_count'], $current['refund_rate']);
            if ($previous !== null) {
                unset($previous['refunds'], $previous['refunds_count'], $previous['refund_rate']);
            }
            unset($change['refunds']);
        }

        $comparison = $report['comparison'];

        return $this->envelope(
            $period,
            array_filter([
                'current' => $current,
                'previous' => $previous,
                'change_percent' => $change === [] ? null : $change,
            ], fn ($value) => $value !== null),
            [
                'orders' => 'Completed orders (cancelled orders are counted separately).',
                'gross' => 'Sum of completed order totals.',
                'net_sales' => 'gross minus refunds paid in the period.',
                'items' => 'Item value before the order discount.',
                'net_profit' => 'Order profit minus the profit lost to refunds.',
                'margin' => 'net_profit / net_sales, percent.',
                'avg_order' => 'net_sales / orders.',
                'discount_rate' => 'discount / items, percent.',
                'cancel_rate' => 'cancelled / (orders + cancelled), percent.',
                'change_percent' => 'Percent change vs the comparison period; *_points are differences in percentage points. null = nothing to compare.',
            ],
            $caveats,
            $orderTypes === [] ? [] : ['order_types' => $orderTypes],
            $comparison !== null ? ['from' => $comparison->startDate(), 'to' => $comparison->endDate()] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $totals
     * @return array<string, int|float>
     */
    private function pick(array $totals): array
    {
        $row = [];
        foreach (self::METRICS as $key) {
            $value = $totals[$key] ?? 0;
            $row[$key] = is_int($value) ? $value : round((float) $value, 2);
        }

        return $row;
    }
}
