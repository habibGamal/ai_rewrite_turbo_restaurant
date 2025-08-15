<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Traits\AdminAccess;
use App\Filament\Traits\ViewerAccess;
use App\Services\ChannelPerformanceReportService;
use App\Filament\Components\PeriodFilterFormComponent;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Forms\Form;

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
                PeriodFilterFormComponent::make(
                    'اختر الفترة الزمنية لتحليل أداء قنوات البيع والمقارنة بينها',
                    'last_30_days',
                    29
                ),
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
