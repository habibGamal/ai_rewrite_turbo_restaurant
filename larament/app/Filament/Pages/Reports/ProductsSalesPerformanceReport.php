<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Traits\AdminAccess;
use App\Filament\Traits\ViewerAccess;
use App\Services\ProductsSalesReportService;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;

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
                Section::make('فترة التقرير')
                    ->description('اختر الفترة الزمنية لعرض أداء المنتجات في المبيعات')
                    ->schema([
                        Select::make('presetPeriod')
                            ->label('فترات محددة مسبقاً')
                            ->options([
                                'today' => 'اليوم',
                                'yesterday' => 'أمس',
                                'last_7_days' => 'آخر 7 أيام',
                                'last_30_days' => 'آخر 30 يوم',
                                'this_week' => 'هذا الأسبوع',
                                'last_week' => 'الأسبوع الماضي',
                                'this_month' => 'هذا الشهر',
                                'last_month' => 'الشهر الماضي',
                                'this_year' => 'هذا العام',
                                'custom' => 'فترة مخصصة',
                            ])
                            ->default('last_30_days')
                            ->live()
                            ->afterStateUpdated(function (callable $set, $state) {
                                match ($state) {
                                    'today' => [
                                        $set('startDate', now()->startOfDay()->toDateString()),
                                        $set('endDate', now()->endOfDay()->toDateString())
                                    ],
                                    'yesterday' => [
                                        $set('startDate', now()->subDay()->startOfDay()->toDateString()),
                                        $set('endDate', now()->subDay()->endOfDay()->toDateString())
                                    ],
                                    'last_7_days' => [
                                        $set('startDate', now()->subDays(6)->startOfDay()->toDateString()),
                                        $set('endDate', now()->endOfDay()->toDateString())
                                    ],
                                    'last_30_days' => [
                                        $set('startDate', now()->subDays(29)->startOfDay()->toDateString()),
                                        $set('endDate', now()->endOfDay()->toDateString())
                                    ],
                                    'this_week' => [
                                        $set('startDate', now()->startOfWeek()->toDateString()),
                                        $set('endDate', now()->endOfWeek()->toDateString())
                                    ],
                                    'last_week' => [
                                        $set('startDate', now()->subWeek()->startOfWeek()->toDateString()),
                                        $set('endDate', now()->subWeek()->endOfWeek()->toDateString())
                                    ],
                                    'this_month' => [
                                        $set('startDate', now()->startOfMonth()->toDateString()),
                                        $set('endDate', now()->endOfMonth()->toDateString())
                                    ],
                                    'last_month' => [
                                        $set('startDate', now()->subMonth()->startOfMonth()->toDateString()),
                                        $set('endDate', now()->subMonth()->endOfMonth()->toDateString())
                                    ],
                                    'this_year' => [
                                        $set('startDate', now()->startOfYear()->toDateString()),
                                        $set('endDate', now()->endOfYear()->toDateString())
                                    ],
                                    default => null
                                };
                            }),
                        DatePicker::make('startDate')
                            ->label('تاريخ البداية')
                            ->default(now()->subDays(29)->startOfDay())
                            ->maxDate(now())
                            ->disabled(fn(callable $get) => $get('presetPeriod') !== 'custom')
                            ->live(),
                        DatePicker::make('endDate')
                            ->label('تاريخ النهاية')
                            ->default(now()->endOfDay())
                            ->maxDate(now())
                            ->disabled(fn(callable $get) => $get('presetPeriod') !== 'custom')
                            ->live(),
                    ])
                    ->columns(3),
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
