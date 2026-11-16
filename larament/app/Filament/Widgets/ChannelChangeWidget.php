<?php

namespace App\Filament\Widgets;

use App\Services\ChannelPerformanceReportService;
use App\Services\Reports\Channels\ChannelReportFilters;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Each channel's change against the comparison period, as bars either side
 * of zero: green for growth, red for decline. Channels with nothing in the
 * comparison period are left out (there is no percentage to draw).
 */
class ChannelChangeWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'التغير عن فترة المقارنة';

    protected ?string $maxHeight = '320px';

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    public ?string $filter = 'net_sales';

    protected function getFilters(): ?array
    {
        return [
            'net_sales' => 'صافي المبيعات',
            'orders' => 'الطلبات',
            'avg_order' => 'متوسط الطلب',
        ];
    }

    public function getDescription(): ?string
    {
        $comparison = ChannelReportFilters::fromArray($this->pageFilters)->comparison();

        return $comparison ? 'مقارنة بـ '.$comparison->label().' · % تغير' : null;
    }

    /**
     * @return list<array{label: string, change: float}>
     */
    public function changes(): array
    {
        $report = app(ChannelPerformanceReportService::class)->report(ChannelReportFilters::fromArray($this->pageFilters));
        $metric = in_array($this->filter, ['net_sales', 'orders', 'avg_order'], true) ? $this->filter : 'net_sales';

        $changes = [];
        foreach ($report['rows'] as $code => $row) {
            $change = ChannelPerformanceReportService::change($row[$metric], $report['previous_rows'][$code][$metric] ?? null);
            if ($change !== null) {
                $changes[] = ['label' => $row['label'], 'change' => $change];
            }
        }

        return $changes;
    }

    protected function getData(): array
    {
        $changes = $this->changes();

        return [
            'labels' => array_column($changes, 'label'),
            'datasets' => [[
                'label' => '% التغير',
                'data' => array_column($changes, 'change'),
                'backgroundColor' => array_map(fn (array $c) => $c['change'] >= 0 ? 'rgba(22, 163, 74, 0.6)' : 'rgba(220, 38, 38, 0.6)', $changes),
                'borderColor' => array_map(fn (array $c) => $c['change'] >= 0 ? 'rgb(22, 163, 74)' : 'rgb(220, 38, 38)', $changes),
                'borderWidth' => 1,
                'borderRadius' => 3,
            ]],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'maintainAspectRatio' => false,
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => ['title' => ['display' => true, 'text' => '% التغير']]],
        ];
    }
}
