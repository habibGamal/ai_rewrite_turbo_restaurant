<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Orders\OrderResource;
use App\Services\ChannelPerformanceReportService;
use App\Services\Reports\Channels\ChannelReportFilters;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * «جدول مقارنة القنوات»: one row per channel and a totals row, sortable by
 * any figure. Optional columns (charges, cancellations, refunds, customers,
 * guests) show only when some channel has them.
 */
class ChannelComparisonTableWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected string $view = 'filament.widgets.channel-comparison-table';

    protected int|string|array $columnSpan = 'full';

    public const SORTABLE = ['label', 'orders', 'net_sales', 'sales_share', 'avg_order', 'discount', 'charges', 'net_profit', 'margin', 'refunds', 'cancel_rate', 'customers', 'avg_per_guest'];

    public string $sortColumn = 'net_sales';

    public string $direction = 'desc';

    public function sortBy(string $column): void
    {
        if (! in_array($column, self::SORTABLE, true)) {
            return;
        }

        if ($this->sortColumn === $column) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortColumn = $column;
        $this->direction = $column === 'label' ? 'asc' : 'desc';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $report = app(ChannelPerformanceReportService::class)->report(ChannelReportFilters::fromArray($this->pageFilters));

        $rows = array_values($report['rows']);
        $sort = in_array($this->sortColumn, self::SORTABLE, true) ? $this->sortColumn : 'net_sales';
        usort($rows, function (array $a, array $b) use ($sort) {
            $result = $sort === 'label' ? strcmp($a['label'], $b['label']) : $a[$sort] <=> $b[$sort];

            return $this->direction === 'asc' ? $result : -$result;
        });

        // A comparison period with no sales has nothing to compare with.
        $previousTotals = $report['previous_totals'];
        $emptyComparison = $previousTotals !== null && $previousTotals['orders'] === 0 && $previousTotals['refunds'] == 0;

        return [
            'rows' => $rows,
            'totals' => $report['totals'],
            'previous' => $report['previous_rows'],
            'previousTotals' => $emptyComparison ? null : $previousTotals,
            'comparison' => $emptyComparison ? null : $report['comparison'],
            'emptyComparison' => $emptyComparison ? $report['comparison'] : null,
            'columns' => $report['columns'],
            'sort' => $sort,
            'direction' => $this->direction,
            'ordersUrl' => fn (string $code): string => OrderResource::getUrl('index', ['filters' => ['type' => ['value' => $code]]]),
        ];
    }
}
