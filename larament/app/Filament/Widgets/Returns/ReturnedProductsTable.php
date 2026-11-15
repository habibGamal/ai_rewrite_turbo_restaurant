<?php

namespace App\Filament\Widgets\Returns;

use App\Models\Product;
use App\Services\Reports\ReturnsAggregates;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

/** The most returned products in scope, split by restocked and damaged. */
class ReturnedProductsTable extends BaseWidget
{
    use InteractsWithPageFilters;
    use InteractsWithReturnsScope;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'أكثر الأصناف ارتجاعاً';

    public function table(Table $table): Table
    {
        $perProduct = app(ReturnsAggregates::class)->linesQuery($this->returnsScope())
            ->select('roi.product_id')
            ->selectRaw('COUNT(DISTINCT orr.id) as returns_count')
            ->selectRaw('SUM(ori.quantity) as returned_quantity')
            ->selectRaw('SUM(CASE WHEN ori.restocked = 1 THEN ori.quantity ELSE 0 END) as restocked_quantity')
            ->selectRaw('SUM(CASE WHEN ori.restocked = 1 THEN 0 ELSE ori.quantity END) as damaged_quantity')
            ->selectRaw('SUM(ori.refund_amount) as refund_amount')
            ->groupBy('roi.product_id');

        return $table
            ->query(
                Product::query()
                    ->joinSub($perProduct, 'prt', 'prt.product_id', '=', 'products.id')
                    ->select(['products.id', 'products.name', 'prt.returns_count', 'prt.returned_quantity', 'prt.restocked_quantity', 'prt.damaged_quantity', 'prt.refund_amount'])
            )
            ->defaultSort('returned_quantity', 'desc')
            ->columns([
                TextColumn::make('name')->label('الصنف')->searchable()->weight('medium'),
                TextColumn::make('returns_count')->label('عدد المرتجعات')->numeric()->sortable(),
                TextColumn::make('returned_quantity')->label('الكمية المرتجعة')->numeric()->sortable(),
                TextColumn::make('restocked_quantity')->label('رجع للمخزن')->numeric()->sortable()->color('success'),
                TextColumn::make('damaged_quantity')->label('تالف')->numeric()->sortable()->color('danger'),
                TextColumn::make('refund_amount')->label('قيمة الرد')->money('EGP')->sortable(),
            ]);
    }
}
