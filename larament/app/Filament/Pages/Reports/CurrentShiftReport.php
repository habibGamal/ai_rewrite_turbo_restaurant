<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Components\ReportTerminalTabs;
use App\Filament\Traits\ViewerAccess;
use App\Filament\Widgets\CurrentShiftCreditCollectionsTable;
use App\Filament\Widgets\CurrentShiftDoneOrdersStats;
use App\Filament\Widgets\CurrentShiftExpensesDetailsTable;
use App\Filament\Widgets\CurrentShiftExpensesTable;
use App\Filament\Widgets\CurrentShiftInfoStats;
use App\Filament\Widgets\CurrentShiftMoneyInfoStats;
use App\Filament\Widgets\CurrentShiftOrdersStats;
use App\Filament\Widgets\CurrentShiftOrdersTable;
use App\Filament\Widgets\NoActiveShiftWidget;
use App\Filament\Widgets\Returns\CurrentShiftReturnsTable;
use App\Filament\Widgets\Terminals\CurrentShiftDrawersTable;
use App\Models\Shift;
use App\Services\ShiftsReportService;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

class CurrentShiftReport extends BaseDashboard
{
    use HasFiltersForm ,ViewerAccess;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string $routePath = 'current-shift-report';

    protected static string|\UnitEnum|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير اليوم';

    protected static ?string $title = 'تقرير اليوم';

    protected static ?int $navigationSort = 1;

    protected ShiftsReportService $shiftsReportService;

    public function boot(): void
    {
        $this->shiftsReportService = app(ShiftsReportService::class);
    }

    /**
     * Sales point tabs (with more than one sales point in the shift).
     */
    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            ReportTerminalTabs::make(fn (): array => [$this->getCurrentShift()->id ?? 0]),
        ]);
    }

    public function getWidgets(): array
    {
        $currentShift = $this->getCurrentShift();

        if (! $currentShift) {
            return [
                NoActiveShiftWidget::class,
            ];
        }

        return [
            CurrentShiftInfoStats::class,
            CurrentShiftMoneyInfoStats::class,
            CurrentShiftOrdersStats::class,
            CurrentShiftDoneOrdersStats::class,
            // Drawers table only while multiple terminals are on.
            ...(CurrentShiftDrawersTable::canView() ? [CurrentShiftDrawersTable::class] : []),
            CurrentShiftOrdersTable::class,
            ...(CurrentShiftReturnsTable::canView() ? [CurrentShiftReturnsTable::class] : []),
            ...(CurrentShiftCreditCollectionsTable::canView() ? [CurrentShiftCreditCollectionsTable::class] : []),
            CurrentShiftExpensesDetailsTable::class,
            CurrentShiftExpensesTable::class,
        ];
    }

    public function getCurrentShift(): ?Shift
    {
        return $this->shiftsReportService->getCurrentShift();
    }
}
