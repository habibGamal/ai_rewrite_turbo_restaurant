<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Traits\AdminAccess;
use App\Filament\Traits\ViewerAccess;
use App\Services\PeakHoursPerformanceReportService;
use App\Filament\Components\PeriodFilterFormComponent;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Forms\Form;

class PeakHoursPerformanceReport extends BaseDashboard
{
    use HasFiltersForm, ViewerAccess;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static string $routePath = 'peak-hours-performance-report';

    protected static ?string $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير أداء ساعات الذروة';

    protected static ?string $title = 'تقرير أداء ساعات الذروة والأنماط الزمنية';

    protected static ?int $navigationSort = 7;

    protected PeakHoursPerformanceReportService $peakHoursReportService;

    public function boot(): void
    {
        $this->peakHoursReportService = app(PeakHoursPerformanceReportService::class);
    }

    public function filtersForm(Form $form): Form
    {
        return $form
            ->schema([
                PeriodFilterFormComponent::make(
                    'اختر الفترة الزمنية لتحليل أداء ساعات الذروة والأنماط الزمنية',
                    'last_30_days',
                    29
                ),
            ]);
    }

    public function getWidgets(): array
    {
        $startDate = $this->filters['startDate'] ?? now()->subDays(29)->startOfDay()->toDateString();
        $endDate = $this->filters['endDate'] ?? now()->endOfDay()->toDateString();

        $ordersCount = $this->peakHoursReportService->getOrdersQuery(
            $startDate,
            $endDate
        )->count();

        if ($ordersCount === 0) {
            return [
                \App\Filament\Widgets\NoCustomersSalesInPeriodWidget::class,
            ];
        }

        return [
            \App\Filament\Widgets\PeakHoursStatsWidget::class,
            \App\Filament\Widgets\HourlyPerformanceChartWidget::class,
            \App\Filament\Widgets\DailyPerformanceChartWidget::class,
            // \App\Filament\Widgets\PeriodPerformanceWidget::class,
            // \App\Filament\Widgets\StaffOptimizationWidget::class,
            // \App\Filament\Widgets\CustomerTrafficPatternsWidget::class,
            // \App\Filament\Widgets\OrderTypeHourlyPerformanceWidget::class,
            // \App\Filament\Widgets\HourlyPerformanceTableWidget::class,
        ];
    }
}
