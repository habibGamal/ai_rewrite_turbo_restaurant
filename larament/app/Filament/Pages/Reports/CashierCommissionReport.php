<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Components\PeriodWithShiftFilterFormComponent;
use App\Filament\Traits\ViewerAccess;
use App\Filament\Widgets\CashierCommissionStatsWidget;
use App\Filament\Widgets\CashierCommissionTableWidget;
use App\Filament\Widgets\NoShiftsInPeriodWidget;
use App\Services\ShiftsReportService;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

class CashierCommissionReport extends BaseDashboard
{
    use HasFiltersForm, ViewerAccess;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string $routePath = 'cashier-commission-report';

    protected static string|\UnitEnum|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير عمولات الكاشير';

    protected static ?string $title = 'تقرير أداء وعمولات الكاشير';

    protected static ?int $navigationSort = 13;

    protected ShiftsReportService $shiftsReportService;

    public function boot(): void
    {
        $this->shiftsReportService = app(ShiftsReportService::class);
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components(
                PeriodWithShiftFilterFormComponent::make(
                    'اختر الفترة الزمنية لعرض تقرير عمولات الكاشير',
                    'اختر الشفتات المحددة',
                    'last_7_days',
                    6
                )
            );
    }

    public function getWidgets(): array
    {
        $filterType = $this->filters['filterType'] ?? 'period';
        $shiftsCount = 0;

        if ($filterType === 'shifts') {
            $shiftIds = $this->filters['shifts'] ?? [];
            $shiftsCount = $this->shiftsReportService->getShiftsCountInPeriod(null, null, $shiftIds);
        } else {
            $startDate = $this->filters['startDate'] ?? now()->subDays(6)->startOfDay()->toDateString();
            $endDate = $this->filters['endDate'] ?? now()->endOfDay()->toDateString();
            $shiftsCount = $this->shiftsReportService->getShiftsCountInPeriod($startDate, $endDate, null);
        }

        if ($shiftsCount === 0) {
            return [
                NoShiftsInPeriodWidget::class,
            ];
        }

        return [
            CashierCommissionStatsWidget::class,
            CashierCommissionTableWidget::class,
        ];
    }
}
