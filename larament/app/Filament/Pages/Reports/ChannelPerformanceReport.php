<?php

namespace App\Filament\Pages\Reports;

use App\Exports\ChannelComparisonExport;
use App\Filament\Components\PeriodFilterFormComponent;
use App\Filament\Traits\ViewerAccess;
use App\Filament\Widgets\ChannelChangeWidget;
use App\Filament\Widgets\ChannelComparisonTableWidget;
use App\Filament\Widgets\ChannelHeatmapWidget;
use App\Filament\Widgets\ChannelKpisWidget;
use App\Filament\Widgets\ChannelPaymentsWidget;
use App\Filament\Widgets\ChannelProductsWidget;
use App\Filament\Widgets\ChannelShareWidget;
use App\Filament\Widgets\ChannelTrendWidget;
use App\Filament\Widgets\NoCustomersSalesInPeriodWidget;
use App\Services\ChannelPerformanceReportService;
use App\Services\Reports\Channels\ChannelReportFilters;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * «تقرير أداء قنوات البيع والمقارنة»: how much each channel (order type)
 * sells and earns, and how that changed against a comparison period.
 */
class ChannelPerformanceReport extends BaseDashboard
{
    use HasFiltersForm, ViewerAccess;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string $routePath = 'channel-performance-report';

    protected static string|\UnitEnum|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير أداء القنوات';

    protected static ?string $title = 'تقرير أداء قنوات البيع والمقارنة';

    protected static ?int $navigationSort = 8;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                PeriodFilterFormComponent::make(
                    'اختر الفترة الزمنية لتحليل أداء قنوات البيع والمقارنة بينها',
                    'last_30_days',
                    29
                ),
                Section::make('المقارنة والقنوات')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Select::make('compareTo')
                            ->label('قارن بـ')
                            ->options(ChannelReportFilters::compareOptions())
                            ->default(ChannelReportFilters::COMPARE_PREVIOUS)
                            ->selectablePlaceholder(false)
                            ->live(),
                        Select::make('channels')
                            ->label('القنوات')
                            ->placeholder('كل القنوات')
                            ->multiple()
                            ->options(fn (): array => app(ChannelPerformanceReportService::class)->channelOptions())
                            ->live(),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportChannels')
                ->label('تصدير Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function (): BinaryFileResponse {
                    $filters = ChannelReportFilters::fromArray($this->filters);
                    $report = app(ChannelPerformanceReportService::class)->report($filters);

                    return Excel::download(
                        new ChannelComparisonExport($report),
                        'channels-'.$filters->startDate().'-'.$filters->endDate().'.xlsx',
                    );
                }),
        ];
    }

    public function getWidgets(): array
    {
        $service = app(ChannelPerformanceReportService::class);
        $filters = ChannelReportFilters::fromArray($this->filters);

        if (! $service->hasOrders($filters)) {
            return [
                NoCustomersSalesInPeriodWidget::class,
            ];
        }

        $widgets = [
            ChannelKpisWidget::class,
            ChannelComparisonTableWidget::class,
        ];

        // Shares and the per-channel change only mean something with two channels or more.
        $severalChannels = $service->ordersQuery($filters)->distinct()->count('orders.type') > 1;
        if ($severalChannels) {
            $widgets[] = ChannelShareWidget::class;
        }

        $comparison = $filters->comparison();
        if ($severalChannels && $comparison !== null && $service->hasOrders($comparison)) {
            $widgets[] = ChannelChangeWidget::class;
        }

        $widgets[] = ChannelTrendWidget::class;
        $widgets[] = ChannelHeatmapWidget::class;
        $widgets[] = ChannelProductsWidget::class;
        $widgets[] = ChannelPaymentsWidget::class;

        return $widgets;
    }
}
