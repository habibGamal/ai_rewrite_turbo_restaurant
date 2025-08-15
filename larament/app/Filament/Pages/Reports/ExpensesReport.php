<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Traits\AdminAccess;
use App\Filament\Traits\ViewerAccess;
use App\Models\Shift;
use App\Services\ShiftsReportService;
use App\Filament\Components\PeriodWithShiftFilterFormComponent;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Forms\Form;

class ExpensesReport extends BaseDashboard
{
    use HasFiltersForm,ViewerAccess;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static string $routePath = 'expenses-report';

    protected static ?string $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير المصروفات';

    protected static ?string $title = 'تقرير المصروفات';

    protected static ?int $navigationSort = 4;

    protected ShiftsReportService $shiftsReportService;

    public function boot(): void
    {
        $this->shiftsReportService = app(ShiftsReportService::class);
    }

    public function filtersForm(Form $form): Form
    {
        return $form
            ->schema(
                PeriodWithShiftFilterFormComponent::make(
                    'اختر الفترة الزمنية لعرض تقارير المصروفات',
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
                \App\Filament\Widgets\NoShiftsInPeriodWidget::class,
            ];
        }

        return [
            \App\Filament\Widgets\PeriodShiftExpensesTable::class,
            \App\Filament\Widgets\PeriodShiftExpensesDetailsTable::class,
        ];
    }
}
