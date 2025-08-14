<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Traits\AdminAccess;
use App\Filament\Traits\ViewerAccess;
use App\Services\ChannelPerformanceReportService;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;

class ChannelPerformanceReport extends BaseDashboard
{
    use HasFiltersForm, ViewerAccess;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static string $routePath = 'channel-performance-report';

    protected static ?string $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير أداء القنوات';

    protected static ?string $title = 'تقرير أداء قنوات البيع والمقارنة';

    protected static ?int $navigationSort = 8;

    protected ChannelPerformanceReportService $channelReportService;

    public function boot(): void
    {
        $this->channelReportService = app(ChannelPerformanceReportService::class);
    }

    public function filtersForm(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('فترة التقرير')
                    ->description('اختر الفترة الزمنية لتحليل أداء قنوات البيع والمقارنة بينها')
                    ->schema([
                        Select::make('presetPeriod')
                            ->label('فترات محددة مسبقاً')
                            ->options([
                                'today' => 'اليوم',
                                'yesterday' => 'أمس',
                                'last_7_days' => 'آخر 7 أيام',
                                'last_14_days' => 'آخر 14 يوم',
                                'last_30_days' => 'آخر 30 يوم',
                                'this_week' => 'هذا الأسبوع',
                                'last_week' => 'الأسبوع الماضي',
                                'this_month' => 'هذا الشهر',
                                'last_month' => 'الشهر الماضي',
                                'last_3_months' => 'آخر 3 شهور',
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
                                    'last_14_days' => [
                                        $set('startDate', now()->subDays(13)->startOfDay()->toDateString()),
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
                                    'last_3_months' => [
                                        $set('startDate', now()->subMonths(2)->startOfMonth()->toDateString()),
                                        $set('endDate', now()->endOfMonth()->toDateString())
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

        $ordersCount = $this->channelReportService->getOrdersQuery(
            $startDate,
            $endDate
        )->count();

        if ($ordersCount === 0) {
            return [
                \App\Filament\Widgets\NoCustomersSalesInPeriodWidget::class,
            ];
        }

        return [
            \App\Filament\Widgets\ChannelPerformanceStatsWidget::class,
            \App\Filament\Widgets\ChannelMarketShareWidget::class,
            // \App\Filament\Widgets\ChannelProfitabilityWidget::class,
            // \App\Filament\Widgets\ChannelTrendsWidget::class,
            // \App\Filament\Widgets\CrossChannelBehaviorWidget::class,
            // \App\Filament\Widgets\ChannelPerformanceTableWidget::class,
        ];
    }
}
