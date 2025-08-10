<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Traits\AdminAccess;
use App\Services\ProductsSalesReportService;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\DatePicker;

class ProductsSalesPerformanceReport extends BaseDashboard
{
    use HasFiltersForm, AdminAccess;

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
                Section::make('فترة التقرير')
                    ->description('اختر الفترة الزمنية لعرض أداء المنتجات في المبيعات')
                    ->schema([
                        DatePicker::make('startDate')
                            ->label('تاريخ البداية')
                            ->default(now()->subDays(30)->startOfDay())
                            ->maxDate(now()),
                        DatePicker::make('endDate')
                            ->label('تاريخ النهاية')
                            ->default(now()->endOfDay())
                            ->maxDate(now()),
                    ])
                    ->columns(2),
            ]);
    }

    public function getWidgets(): array
    {
        $startDate = $this->filters['startDate'] ?? now()->subDays(30)->startOfDay()->toDateString();
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
