<?php

namespace App\Filament\Widgets;

use App\Services\ChannelPerformanceReportService;
use App\Services\Reports\Channels\ChannelReportFilters;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * One line per channel over the period, by day, week or month depending on
 * the period's length.
 */
class ChannelTrendWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'الاتجاه عبر الوقت';

    protected ?string $maxHeight = '360px';

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
        return match (ChannelPerformanceReportService::granularity(ChannelReportFilters::fromArray($this->pageFilters))) {
            'week' => 'أسبوعي (الأسبوع يبدأ السبت)',
            'month' => 'شهري',
            default => 'يومي',
        };
    }

    protected function getData(): array
    {
        $trend = app(ChannelPerformanceReportService::class)->trend(ChannelReportFilters::fromArray($this->pageFilters));
        $metric = $this->filter ?? 'net_sales';

        $datasets = [];
        foreach ($trend['series'] as $line) {
            $datasets[] = [
                'label' => $line['label'],
                'data' => array_values(array_map(fn (array $value) => match ($metric) {
                    'orders' => $value['orders'],
                    'avg_order' => $value['orders'] > 0 ? round($value['net_sales'] / $value['orders'], 2) : null,
                    default => $value['net_sales'],
                }, $line['values'])),
                'borderColor' => $line['chart'],
                'backgroundColor' => $line['chart'],
                'borderWidth' => 2,
                'pointRadius' => count($trend['buckets']) > 31 ? 0 : 3,
                'tension' => 0.25,
                'spanGaps' => true,
                'fill' => false,
            ];
        }

        return [
            'labels' => array_values($trend['buckets']),
            'datasets' => $datasets,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'maintainAspectRatio' => false,
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => ['y' => ['beginAtZero' => true]],
        ];
    }
}
