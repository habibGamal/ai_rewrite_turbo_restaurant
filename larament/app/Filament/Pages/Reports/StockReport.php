<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Components\PeriodFilterFormComponent;
use App\Filament\Traits\ViewerAccess;
use App\Filament\Widgets\StockReportTable;
use App\Models\Warehouse;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StockReport extends BaseDashboard
{
    use HasFiltersForm ,ViewerAccess;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static string $routePath = 'stock-report';

    protected static string|\UnitEnum|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير المخزون';

    protected static ?string $title = 'تقرير المخزون';

    protected static ?int $navigationSort = 1;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                PeriodFilterFormComponent::make(
                    'اختر الفترة الزمنية لتحليل المخزون',
                    'last_30_days',
                    29
                ),
                Section::make('المخزن')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('warehouse_id')
                            ->label('المخزن')
                            ->placeholder('كل المخازن')
                            ->options(fn (): array => Warehouse::query()
                                ->active()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->live(),
                    ]),
            ]);
    }

    public function getWidgets(): array
    {
        return [
            StockReportTable::class,
        ];
    }
}
