<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Traits\AdminAccess;
use App\Filament\Traits\ViewerAccess;
use App\Services\ProductsSalesReportService;
use App\Filament\Components\PeriodFilterFormComponent;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Forms\Form;

class ProductsSalesPerformanceReport extends BaseDashboard
{
    use HasFiltersForm, ViewerAccess;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static string $routePath = 'products-sales-performance-report';

    protected static ?string $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير أداء المنتجات';

    protected static ?string $title = 'تقرير أداء المنتجات في المبيعات';

    protected static ?int $navigationSort = 4;

    protected ProductsSalesReportService $productsReportService;

    public function boot(): void
    {
        $this->productsReportService = app(ProductsSalesReportService::class);
    }

    public function filtersForm(Form $form): Form
    {
        return $form
            ->schema([
                PeriodFilterFormComponent::make(
                    'اختر الفترة الزمنية لعرض أداء المنتجات في المبيعات',
                    'last_30_days',
                    29
                ),
            ]);
    }

    public function getWidgets(): array
    {
        $startDate = $this->filters['startDate'] ?? now()->subDays(29)->startOfDay()->toDateString();
        $endDate = $this->filters['endDate'] ?? now()->endOfDay()->toDateString();
        $ordersCount = $this->productsReportService->getOrdersQuery(
            $startDate,
            $endDate
        )->count();

        if ($ordersCount === 0) {
            return [
                \App\Filament\Widgets\NoProductsSalesInPeriodWidget::class,
            ];
        }

        return [
            \App\Filament\Widgets\ProductsSalesStatsWidget::class,
            \App\Filament\Widgets\TopProductsBySalesWidget::class,
            \App\Filament\Widgets\TopProductsByProfitWidget::class,
            \App\Filament\Widgets\OrderTypePerformanceWidget::class,
            \App\Filament\Widgets\CategoryPerformanceWidget::class,
            \App\Filament\Widgets\ProductsSalesTableWidget::class,
        ];
    }

}
