<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Components\PeriodWithShiftFilterFormComponent;
use App\Filament\Traits\ViewerAccess;
use App\Filament\Widgets\CustomFees\CustomFeesLinesTable;
use App\Filament\Widgets\CustomFees\CustomFeesSummaryTable;
use App\Filament\Widgets\NoShiftsInPeriodWidget;
use App\Services\ShiftsReportService;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

/**
 * «تقرير الرسوم الإضافية»: custom fee lines of completed orders, grouped by
 * name and listed line by line. Hidden while the custom fees feature is off.
 */
class CustomFeesReport extends BaseDashboard
{
    use HasFiltersForm, ViewerAccess;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-plus-circle';

    protected static string $routePath = 'custom-fees-report';

    protected static string|\UnitEnum|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير الرسوم الإضافية';

    protected static ?string $title = 'تقرير الرسوم الإضافية';

    protected static ?int $navigationSort = 5;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components(
            PeriodWithShiftFilterFormComponent::make(
                'اختر الفترة الزمنية لعرض الرسوم الإضافية',
                'اختر الشفتات المحددة',
                'last_7_days',
                6
            )
        );
    }

    public function getWidgets(): array
    {
        $service = app(ShiftsReportService::class);
        [$startDate, $endDate, $shiftIds] = $service->scopeFromFilters($this->filters);

        if ($service->getShiftsCountInPeriod($startDate, $endDate, $shiftIds) === 0) {
            return [NoShiftsInPeriodWidget::class];
        }

        return [
            CustomFeesSummaryTable::class,
            CustomFeesLinesTable::class,
        ];
    }
}
