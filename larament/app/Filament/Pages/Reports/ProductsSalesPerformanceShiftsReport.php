<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Components\PeriodWithShiftFilterFormComponent;
use App\Filament\Traits\ViewerAccess;
use App\Filament\Widgets\CategoryPerformanceWidget;
use App\Filament\Widgets\NoProductsSalesInPeriodWidget;
use App\Filament\Widgets\OrderTypePerformanceWidget;
use App\Filament\Widgets\ProductsSalesStatsWidget;
use App\Filament\Widgets\ProductsSalesTableWidget;
use App\Filament\Widgets\TopProductsByProfitWidget;
use App\Filament\Widgets\TopProductsBySalesWidget;
use App\Services\ProductsSalesReportService;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

class ProductsSalesPerformanceShiftsReport extends BaseDashboard
{
    use HasFiltersForm, ViewerAccess;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string $routePath = 'products-sales-performance-shifts-report';

    protected static string|\UnitEnum|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير أداء المنتجات بالشفتات';

    protected static ?string $title = 'تقرير أداء المنتجات في المبيعات بالشفتات';

    protected static ?int $navigationSort = 6;

    protected ProductsSalesReportService $productsReportService;

    public function boot(): void
    {
        $this->productsReportService = app(ProductsSalesReportService::class);
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components(
                PeriodWithShiftFilterFormComponent::make(
                    'اختر الفترة الزمنية لعرض أداء المنتجات في المبيعات',
                    'اختر الشفتات المحددة',
                    'last_30_days',
                    29
                )
            );
    }

    public function getWidgets(): array
    {
        $filterType = $this->filters['filterType'] ?? 'period';

        if ($filterType === 'shifts') {
            $shiftIds = $this->filters['shifts'] ?? [];
            $ordersCount = $this->productsReportService->getOrdersQuery(null, null, $shiftIds)->count();
        } else {
            $startDate = $this->filters['startDate'] ?? now()->subDays(29)->startOfDay()->toDateString();
            $endDate = $this->filters['endDate'] ?? now()->endOfDay()->toDateString();
            $ordersCount = $this->productsReportService->getOrdersQuery($startDate, $endDate, null)->count();
        }

        if ($ordersCount === 0) {
            return [
                NoProductsSalesInPeriodWidget::class,
            ];
        }

        return [
            ProductsSalesStatsWidget::class,
            TopProductsBySalesWidget::class,
            TopProductsByProfitWidget::class,
            OrderTypePerformanceWidget::class,
            CategoryPerformanceWidget::class,
            ProductsSalesTableWidget::class,
        ];
    }
}
