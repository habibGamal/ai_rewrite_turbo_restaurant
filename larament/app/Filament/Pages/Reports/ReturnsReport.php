<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Components\PeriodWithShiftFilterFormComponent;
use App\Filament\Traits\ViewerAccess;
use App\Filament\Widgets\Returns\ReturnedProductsTable;
use App\Filament\Widgets\Returns\ReturnLinesTable;
use App\Filament\Widgets\Returns\ReturnsReportStats;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

/**
 * «تقرير المرتجعات»: returns paid by the shifts in scope, with lines marked
 * restocked or damaged, refund methods, exchanges and the most returned products.
 */
class ReturnsReport extends BaseDashboard
{
    use HasFiltersForm, ViewerAccess;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static string $routePath = 'returns-report';

    protected static string|\UnitEnum|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير المرتجعات';

    protected static ?string $title = 'تقرير المرتجعات';

    protected static ?int $navigationSort = 6;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components(
                PeriodWithShiftFilterFormComponent::make(
                    'اختر الفترة الزمنية لعرض المرتجعات',
                    'اختر الشفتات المحددة',
                    'last_7_days',
                    6
                )
            );
    }

    public function getWidgets(): array
    {
        return [
            ReturnsReportStats::class,
            ReturnLinesTable::class,
            ReturnedProductsTable::class,
        ];
    }
}
