<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Components\PeriodWithShiftFilterFormComponent;
use App\Filament\Traits\ViewerAccess;
use App\Filament\Widgets\EquipmentReportStats;
use App\Filament\Widgets\EquipmentReportTable;
use App\Models\OrderTypeRow;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * «تقرير المعدات»: per equipment uses, revenue, cost and profit of completed
 * orders, by period or shifts and optionally one order type.
 */
class EquipmentReport extends BaseDashboard
{
    use HasFiltersForm, ViewerAccess;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static string $routePath = 'equipment-report';

    protected static string|\UnitEnum|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير المعدات';

    protected static ?string $title = 'تقرير المعدات';

    protected static ?int $navigationSort = 21;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            ...PeriodWithShiftFilterFormComponent::make(
                'اختر الفترة الزمنية لتقرير المعدات',
                'اختر الشفتات المحددة',
                'last_7_days',
                6
            ),
            Section::make('تصفية التقرير')
                ->columnSpanFull()
                ->schema([
                    Select::make('order_type')
                        ->label('نوع الطلب')
                        ->placeholder('كل الأنواع')
                        ->options(fn () => OrderTypeRow::query()->orderBy('id')->pluck('label', 'code'))
                        ->live(),
                ]),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            EquipmentReportStats::class,
            EquipmentReportTable::class,
        ];
    }
}
