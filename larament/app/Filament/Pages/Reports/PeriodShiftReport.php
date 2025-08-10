<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Traits\AdminAccess;
use App\Services\ShiftsReportService;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\DatePicker;

class PeriodShiftReport extends BaseDashboard
{
    use HasFiltersForm, AdminAccess;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static string $routePath = 'period-shift-report';

    protected static ?string $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير فترة الشفتات';

    protected static ?string $title = 'تقرير فترة الشفتات';

    protected static ?int $navigationSort = 3;

    protected ShiftsReportService $shiftsReportService;

    public function boot(): void
    {
        $this->shiftsReportService = app(ShiftsReportService::class);
    }

    public function filtersForm(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('فترة التقرير')
                    ->description('اختر الفترة الزمنية لعرض تقارير الشفتات')
                    ->schema([
                        DatePicker::make('startDate')
                            ->label('تاريخ البداية')
                            ->default(now()->subDays(7)->startOfDay())
                            ->maxDate(now()),
                        DatePicker::make('endDate')
                            ->label('تاريخ النهاية')
                            ->default(now()->endOfDay())
                            ->maxDate(now()),
                    ])
                    ->columns(2),
            ]);
    }

    public function getWidgets(): array
    {
        $startDate = $this->filters['startDate'] ?? now()->subDays(7)->startOfDay()->toDateString();
        $endDate = $this->filters['endDate'] ?? now()->endOfDay()->toDateString();

        $shiftsCount = $this->shiftsReportService->getShiftsCountInPeriod($startDate, $endDate);

        if ($shiftsCount === 0) {
            return [
                \App\Filament\Widgets\NoShiftsInPeriodWidget::class,
            ];
        }

        return [
            \App\Filament\Widgets\PeriodShiftInfoStats::class,
            \App\Filament\Widgets\PeriodShiftMoneyInfoStats::class,
            \App\Filament\Widgets\PeriodShiftOrdersStats::class,
            \App\Filament\Widgets\PeriodShiftDoneOrdersStats::class,
            \App\Filament\Widgets\PeriodShiftOrdersTable::class,
            \App\Filament\Widgets\PeriodShiftExpensesTable::class,
        ];
    }
}
