<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Components\DailyComparisonFilterFormComponent;
use App\Filament\Traits\ViewerAccess;
use App\Filament\Widgets\DailyComparisonChartWidget;
use App\Filament\Widgets\DailyComparisonStatsWidget;
use App\Filament\Widgets\DailyComparisonTableWidget;
use App\Filament\Widgets\NoCustomersSalesInPeriodWidget;
use App\Services\DailyComparisonReportService;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

class DailyComparisonReport extends BaseDashboard
{
    use HasFiltersForm, ViewerAccess;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static string $routePath = 'daily-comparison-report';

    protected static string|\UnitEnum|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير مقارنة الأيام والفترات';

    protected static ?string $title = 'تقرير مقارنة أداء أيام الأسبوع والشهر والشهور';

    protected static ?int $navigationSort = 9;

    protected DailyComparisonReportService $reportService;

    public function boot(): void
    {
        $this->reportService = app(DailyComparisonReportService::class);
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                DailyComparisonFilterFormComponent::make(),
            ]);
    }

    public function getWidgets(): array
    {
        $startDate = $this->filters['startDate'] ?? now()->startOfMonth()->toDateString();
        $endDate = $this->filters['endDate'] ?? now()->endOfMonth()->toDateString();

        $ordersCount = $this->reportService->getOrdersQuery(
            $startDate,
            $endDate
        )->count();

        if ($ordersCount === 0) {
            return [
                NoCustomersSalesInPeriodWidget::class,
            ];
        }

        return [
            DailyComparisonStatsWidget::class,
            DailyComparisonChartWidget::class,
            DailyComparisonTableWidget::class,
        ];
    }
}
