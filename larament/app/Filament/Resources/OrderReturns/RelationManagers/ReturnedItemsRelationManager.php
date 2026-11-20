<?php

namespace App\Filament\Resources\OrderReturns\RelationManagers;

use App\Models\OrderReturnItem;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReturnedItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'أصناف المرتجع';

    protected static ?string $label = 'صنف';

    protected static ?string $pluralLabel = 'أصناف';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // View-only
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(OrderReturnItem::LINE_NAME_RELATIONS))
            ->columns([
                TextColumn::make('line_name')
                    ->label('المنتج')
                    ->state(fn (OrderReturnItem $record): string => $record->lineName())
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $inner) => $inner
                        ->whereHas('orderItem.product', fn (Builder $product) => $product->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('orderFee', fn (Builder $fee) => $fee->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('orderEquipment', fn (Builder $line) => $line->where('name', 'like', "%{$search}%")))),

                TextColumn::make('quantity')
                    ->label('الكمية المرتجعة')
                    ->numeric(),

                TextColumn::make('refund_amount')
                    ->label('مبلغ الاسترجاع')
                    ->money('EGP'),

                TextColumn::make('original_quantity')
                    ->label('الكمية الأصلية')
                    ->state(fn (OrderReturnItem $record) => $record->orderItem?->quantity ?? $record->orderEquipment?->quantity)
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('original_price')
                    ->label('السعر الأصلي')
                    ->state(fn (OrderReturnItem $record) => $record->orderItem?->price ?? $record->orderEquipment?->unit_price ?? $record->orderFee?->amount)
                    ->money('EGP')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                // No actions
            ])
            ->recordActions([
                // No actions
            ])
            ->toolbarActions([
                // No bulk actions
            ]);
    }
}
