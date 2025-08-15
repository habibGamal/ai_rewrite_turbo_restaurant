<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Traits\AdminAccess;
use App\Filament\Traits\ViewerAccess;
use App\Services\CustomersPerformanceReportService;
use App\Filament\Components\PeriodFilterFormComponent;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Forms\Form;

class CustomersPerformanceReport extends BaseDashboard
{
    use HasFiltersForm, ViewerAccess;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static string $routePath = 'customers-performance-report';

    protected static ?string $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير أداء العملاء';

    protected static ?string $title = 'تقرير أداء العملاء في المبيعات';

    protected static ?int $navigationSort = 5;

    protected CustomersPerformanceReportService $customersReportService;

    public function boot(): void
    {
        $this->customersReportService = app(CustomersPerformanceReportService::class);
    }

    public function filtersForm(Form $form): Form
    {
        return $form
            ->schema([
                PeriodFilterFormComponent::make(
                    'اختر الفترة الزمنية لعرض أداء العملاء في المبيعات',
                    'last_30_days',
                    29
                ),
            ]);
    }

    public function getWidgets(): array
    {
        $startDate = $this->filters['startDate'] ?? now()->subDays(29)->startOfDay()->toDateString();
        $endDate = $this->filters['endDate'] ?? now()->endOfDay()->toDateString();
        $ordersCount = $this->customersReportService->getOrdersQuery(
            $startDate,
            $endDate
        )->count();

        if ($ordersCount === 0) {
            return [
                \App\Filament\Widgets\NoCustomersSalesInPeriodWidget::class,
            ];
        }

        return [
            \App\Filament\Widgets\CustomersPerformanceStatsWidget::class,
            \App\Filament\Widgets\CustomerLoyaltyInsightsWidget::class,
            \App\Filament\Widgets\TopCustomersBySalesWidget::class,
            \App\Filament\Widgets\TopCustomersByProfitWidget::class,
            \App\Filament\Widgets\CustomerSegmentsWidget::class,
            \App\Filament\Widgets\CustomerOrderTypePerformanceWidget::class,
            \App\Filament\Widgets\CustomerActivityTrendWidget::class,
            \App\Filament\Widgets\CustomersPerformanceTableWidget::class,
        ];
    }
}
