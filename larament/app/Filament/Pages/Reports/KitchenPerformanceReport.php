<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Components\PeriodFilterFormComponent;
use App\Filament\Traits\ViewerAccess;
use App\Filament\Widgets\KitchenPerformanceOrderTypesWidget;
use App\Filament\Widgets\KitchenPerformanceSlowestOrdersWidget;
use App\Filament\Widgets\KitchenPerformanceSlowestProductsWidget;
use App\Filament\Widgets\KitchenPerformanceStationsWidget;
use App\Filament\Widgets\KitchenPerformanceStatsWidget;
use App\Filament\Widgets\KitchenPerformanceWasteCandidatesWidget;
use App\Models\KitchenStation;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class KitchenPerformanceReport extends BaseDashboard
{
    use HasFiltersForm, ViewerAccess;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-fire';

    protected static string $routePath = 'kitchen-performance-report';

    protected static string|\UnitEnum|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير أداء المطبخ';

    protected static ?string $title = 'تقرير أداء المطبخ';

    protected static ?int $navigationSort = 8;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                PeriodFilterFormComponent::make(
                    'اختر الفترة الزمنية لعرض تقرير أداء المطبخ',
                    'last_30_days',
                    29
                ),
                Section::make('المحطة')
                    ->description('اختر محطة مطبخ لعرض أدائها فقط')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('kitchen_station_id')
                            ->label('محطة المطبخ')
                            ->placeholder('كل المحطات')
                            ->options(fn (): array => KitchenStation::query()
                                ->orderBy('sort_order')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->live(),
                    ])
                    ->columns(1),
            ]);
    }

    public function getWidgets(): array
    {
        return [
            KitchenPerformanceStatsWidget::class,
            KitchenPerformanceOrderTypesWidget::class,
            KitchenPerformanceSlowestOrdersWidget::class,
            KitchenPerformanceSlowestProductsWidget::class,
            KitchenPerformanceStationsWidget::class,
            KitchenPerformanceWasteCandidatesWidget::class,
        ];
    }
}
