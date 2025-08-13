<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Traits\AdminAccess;
use App\Services\ShiftsReportService;
use Filament\Pages\Page;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use App\Models\Shift;

class CurrentShiftReport extends BaseDashboard
{
    use HasFiltersForm, AdminAccess;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static string $routePath = 'current-shift-report';

    protected static ?string $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير اليوم';

    protected static ?string $title = 'تقرير اليوم';

    protected static ?int $navigationSort = 1;

    protected ShiftsReportService $shiftsReportService;

    public function boot(): void
    {
        $this->shiftsReportService = app(ShiftsReportService::class);
    }

    public function getWidgets(): array
    {
        $currentShift = $this->getCurrentShift();

        if (!$currentShift) {
            return [
                \App\Filament\Widgets\NoActiveShiftWidget::class,
            ];
        }

        return [
            \App\Filament\Widgets\CurrentShiftInfoStats::class,
            \App\Filament\Widgets\CurrentShiftMoneyInfoStats::class,
            \App\Filament\Widgets\CurrentShiftOrdersStats::class,
            \App\Filament\Widgets\CurrentShiftDoneOrdersStats::class,
            \App\Filament\Widgets\CurrentShiftOrdersTable::class,
            \App\Filament\Widgets\CurrentShiftExpensesDetailsTable::class,
            \App\Filament\Widgets\CurrentShiftExpensesTable::class,
        ];
    }

    public function getCurrentShift(): ?Shift
    {
        return $this->shiftsReportService->getCurrentShift();
    }
}
