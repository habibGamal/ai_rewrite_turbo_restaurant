<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Components\PeriodFilterFormComponent;
use App\Filament\Traits\ViewerAccess;
use App\Models\Category;
use App\Models\Product;
use App\Models\Warehouse;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

abstract class InventoryDocumentReport extends Dashboard
{
    use HasFiltersForm, ViewerAccess;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static string|\UnitEnum|null $navigationGroup = 'التقارير';

    protected static ?int $navigationSort = 2;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            PeriodFilterFormComponent::make('اختر الفترة الزمنية للتقرير', 'last_30_days', 29),
            Section::make('تصفية التقرير')->columnSpanFull()->columns(4)->schema([
                Select::make('warehouse_id')->label('المخزن')->placeholder('كل المخازن')
                    ->options(fn () => Warehouse::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()->live(),
                Select::make('main_category_id')->label('التصنيف الرئيسي')->placeholder('كل التصنيفات')
                    ->options(fn () => Category::query()->whereNull('parent_id')->orderBy('name')->pluck('name', 'id'))
                    ->afterStateUpdated(function (Set $set): void {
                        $set('sub_category_id', null);
                        $set('product_id', null);
                    })->searchable()->live(),
                Select::make('sub_category_id')->label('التصنيف الفرعي')->placeholder('كل التصنيفات الفرعية')
                    ->options(fn (Get $get) => Category::query()->whereNotNull('parent_id')
                        ->when($get('main_category_id'), fn ($query, $id) => $query->where('parent_id', $id))
                        ->orderBy('name')->pluck('name', 'id'))
                    ->afterStateUpdated(fn (Set $set) => $set('product_id', null))->searchable()->live(),
                Select::make('product_id')->label('المنتج')->placeholder('كل المنتجات')
                    ->options(fn (Get $get) => Product::query()
                        ->when($get('sub_category_id'), fn ($query, $id) => $query->where('category_id', $id))
                        ->when($get('main_category_id'), fn ($query, $id) => $query->whereHas('category', fn ($query) => $query->where('id', $id)->orWhere('parent_id', $id)))
                        ->orderBy('name')->pluck('name', 'id'))
                    ->searchable()->live(),
            ]),
        ]);
    }
}
