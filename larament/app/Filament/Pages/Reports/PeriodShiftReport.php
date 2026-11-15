<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Components\PeriodWithShiftFilterFormComponent;
use App\Filament\Traits\ViewerAccess;
use App\Filament\Widgets\NoShiftsInPeriodWidget;
use App\Filament\Widgets\PeriodShiftCreditCollectionsTable;
use App\Filament\Widgets\PeriodShiftDoneOrdersStats;
use App\Filament\Widgets\PeriodShiftExpensesDetailsTable;
use App\Filament\Widgets\PeriodShiftExpensesTable;
use App\Filament\Widgets\PeriodShiftInfoStats;
use App\Filament\Widgets\PeriodShiftMoneyInfoStats;
use App\Filament\Widgets\PeriodShiftOrdersStats;
use App\Filament\Widgets\PeriodShiftOrdersTable;
use App\Services\ShiftsReportService;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

class PeriodShiftReport extends BaseDashboard
{
    use HasFiltersForm,ViewerAccess;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string $routePath = 'period-shift-report';

    protected static string|\UnitEnum|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير فترة الشفتات';

    protected static ?string $title = 'تقرير فترة الشفتات';

    protected static ?int $navigationSort = 3;

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
                    'اختر الفترة الزمنية لعرض تقارير الشفتات',
                    'اختر الشفتات المحددة',
                    'last_7_days',
                    6
                )
            );
    }

    public function getWidgets(): array
    {
        $shiftsCount = $this->shiftsReportService->getShiftsCountInPeriod(
            ...$this->shiftsReportService->scopeFromFilters($this->filters)
        );

        if ($shiftsCount === 0) {
            return [
                NoShiftsInPeriodWidget::class,
            ];
        }

        return [
            PeriodShiftInfoStats::class,
            PeriodShiftMoneyInfoStats::class,
            PeriodShiftOrdersStats::class,
            PeriodShiftDoneOrdersStats::class,
            PeriodShiftOrdersTable::class,
            PeriodShiftCreditCollectionsTable::class,
            PeriodShiftExpensesDetailsTable::class,
            PeriodShiftExpensesTable::class,
        ];
    }
}
