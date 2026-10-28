<?php

namespace App\Filament\Widgets;

use App\Services\DailyComparisonReportService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class DailyComparisonChartWidget extends ChartWidget
{
    protected ?string $heading = 'مخطط مقارنة الأداء (المبيعات والأرباح والطلبات ومتوسط الطلب)';

    protected ?string $description = 'مقارنة بيانية يومية/أسبوعية/شهرية مع مقياس مزدوج للمبالغ النقدية وعدد الطلبات';

    protected ?string $maxHeight = '420px';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    use InteractsWithPageFilters;

    protected DailyComparisonReportService $reportService;

    public function boot(): void
    {
        $this->reportService = app(DailyComparisonReportService::class);
    }

    protected function getFilters(): ?array
    {
        return [
            'all' => 'جميع المؤشرات',
            'sales_profit' => 'المبيعات والأرباح فقط',
            'orders' => 'عدد الطلبات فقط',
            'avg_order' => 'متوسط قيمة الطلب فقط',
        ];
    }

    protected function getData(): array
    {
        $startDate = $this->pageFilters['startDate'] ?? now()->startOfMonth()->toDateString();
        $endDate = $this->pageFilters['endDate'] ?? now()->endOfMonth()->toDateString();
        $viewType = $this->pageFilters['viewType'] ?? 'by_date';
        $activeFilter = $this->filter ?? 'all';

        $data = $this->reportService->getPerformanceData($startDate, $endDate, $viewType);

        $labels = $data->pluck('day_label')->toArray();
        $salesData = $data->pluck('total_sales')->toArray();
        $profitData = $data->pluck('total_profit')->toArray();
        $ordersData = $data->pluck('total_orders')->toArray();
        $avgOrderData = $data->pluck('avg_order_value')->toArray();

        $allDatasets = [
            'sales' => [
                'label' => 'المبيعات (ج.م)',
                'data' => $salesData,
                'backgroundColor' => 'rgba(16, 185, 129, 0.65)',
                'borderColor' => 'rgb(16, 185, 129)',
                'borderWidth' => 2,
                'borderRadius' => 4,
                'type' => 'bar',
                'yAxisID' => 'y',
            ],
            'profit' => [
                'label' => 'الأرباح (ج.م)',
                'data' => $profitData,
                'backgroundColor' => 'rgba(245, 158, 11, 0.65)',
                'borderColor' => 'rgb(245, 158, 11)',
                'borderWidth' => 2,
                'borderRadius' => 4,
                'type' => 'bar',
                'yAxisID' => 'y',
            ],
            'orders' => [
                'label' => 'عدد الطلبات',
                'data' => $ordersData,
                'backgroundColor' => 'rgba(59, 130, 246, 0.8)',
                'borderColor' => 'rgb(59, 130, 246)',
                'borderWidth' => 2.5,
                'pointRadius' => 4,
                'pointHoverRadius' => 6,
                'fill' => false,
                'type' => 'line',
                'yAxisID' => 'y1',
                'tension' => 0.25,
            ],
            'avg_order' => [
                'label' => 'متوسط قيمة الطلب (ج.م)',
                'data' => $avgOrderData,
                'backgroundColor' => 'rgba(168, 85, 247, 0.8)',
                'borderColor' => 'rgb(168, 85, 247)',
                'borderWidth' => 2,
                'borderDash' => [5, 5],
                'pointRadius' => 3,
                'fill' => false,
                'type' => 'line',
                'yAxisID' => 'y',
                'tension' => 0.25,
            ],
        ];

        $selectedDatasets = match ($activeFilter) {
            'sales_profit' => [$allDatasets['sales'], $allDatasets['profit']],
            'orders' => [$allDatasets['orders']],
            'avg_order' => [$allDatasets['avg_order']],
            default => array_values($allDatasets),
        };

        return [
            'datasets' => $selectedDatasets,
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'interaction' => [
                'mode' => 'index',
                'intersect' => false,
            ],
            'scales' => [
                'y' => [
                    'type' => 'linear',
                    'display' => true,
                    'position' => 'left',
                    'title' => [
                        'display' => true,
                        'text' => 'المبالغ (ج.م)',
                    ],
                ],
                'y1' => [
                    'type' => 'linear',
                    'display' => true,
                    'position' => 'right',
                    'title' => [
                        'display' => true,
                        'text' => 'عدد الطلبات',
                    ],
                    'grid' => [
                        'drawOnChartArea' => false,
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'position' => 'top',
                ],
                'tooltip' => [
                    'mode' => 'index',
                    'intersect' => false,
                ],
            ],
        ];
    }
}
