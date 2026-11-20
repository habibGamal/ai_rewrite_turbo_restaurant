<?php

namespace App\Filament\Pages\Reports;

use App\Enums\ProductType;
use App\Filament\Components\PeriodFilterFormComponent;
use App\Filament\Traits\ViewerAccess;
use App\Filament\Widgets\ItemLedgerReportTable;
use App\Models\Category;
use App\Models\Product;
use App\Models\Warehouse;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ItemLedgerReport extends BaseDashboard
{
    use HasFiltersForm, ViewerAccess;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static string $routePath = 'item-ledger-report';

    protected static string|\UnitEnum|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'كشف حركة صنف';

    protected static ?string $title = 'كشف حركة صنف';

    protected static ?int $navigationSort = 18;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                PeriodFilterFormComponent::make(
                    'اختر الفترة الزمنية لكشف حركة الصنف',
                    'last_30_days',
                    29
                ),
                Section::make('فلاتر المخزون والتصنيف')
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

                        Select::make('main_category_id')
                            ->label('التصنيف الرئيسي')
                            ->placeholder('كل التصنيفات الرئيسية')
                            ->options(fn (): array => Category::query()
                                ->whereNull('parent_id')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('sub_category_id', null);
                                $set('product_id', null);
                            }),

                        Select::make('sub_category_id')
                            ->label('التصنيف الفرعي')
                            ->placeholder('كل التصنيفات الفرعية')
                            ->options(function (Get $get): array {
                                $mainId = $get('main_category_id');

                                return Category::query()
                                    ->whereNotNull('parent_id')
                                    ->when(is_numeric($mainId), fn (Builder $query) => $query->where('parent_id', (int) $mainId))
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('product_id', null)),

                        Select::make('product_id')
                            ->label('الصنف')
                            ->placeholder('كل الأصناف')
                            ->options(function (Get $get): array {
                                $mainId = $get('main_category_id');
                                $subId = $get('sub_category_id');

                                return Product::query()
                                    ->whereNot('type', ProductType::Manufactured)
                                    ->when(is_numeric($subId), fn (Builder $query) => $query->where('category_id', (int) $subId))
                                    ->when(is_numeric($mainId) && ! is_numeric($subId), function (Builder $query) use ($mainId) {
                                        $query->whereIn(
                                            'category_id',
                                            Category::query()
                                                ->select('id')
                                                ->where('id', (int) $mainId)
                                                ->orWhere('parent_id', (int) $mainId)
                                        );
                                    })
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->searchable()
                            ->live(),
                    ])
                    ->columns(4),
            ]);
    }

    public function getWidgets(): array
    {
        return [
            ItemLedgerReportTable::class,
        ];
    }
}
