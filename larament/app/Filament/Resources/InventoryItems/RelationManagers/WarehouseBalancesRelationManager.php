<?php

namespace App\Filament\Resources\InventoryItems\RelationManagers;

use App\Filament\Resources\InventoryItems\InventoryItemResource;
use App\Models\InventoryItem;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WarehouseBalancesRelationManager extends RelationManager
{
    protected static string $relationship = 'warehouseBalances';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return 'أرصدة المخازن';
    }

    public static function getModelLabel(): string
    {
        return 'رصيد مخزن';
    }

    public static function getPluralModelLabel(): string
    {
        return 'أرصدة المخازن';
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['warehouse', 'product']))
            ->columns([
                TextColumn::make('warehouse.name')
                    ->label('المخزن')
                    ->badge()
                    ->color(fn (InventoryItem $record): string => $record->getKey() === $this->getOwnerRecord()->getKey() ? 'primary' : 'gray')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label('الكمية')
                    ->numeric(decimalPlaces: 3)
                    ->sortable()
                    ->badge()
                    ->color(fn (InventoryItem $record): string => match (true) {
                        $record->quantity > ($record->product->min_stock * 2) => 'success',
                        $record->quantity > $record->product->min_stock => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('updated_at')
                    ->label('تاريخ التحديث')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('view_warehouse_item')
                    ->label('عرض التفاصيل')
                    ->icon('heroicon-o-eye')
                    ->color('primary')
                    ->url(fn (InventoryItem $record): string => InventoryItemResource::getUrl('view', ['record' => $record]))
                    ->visible(fn (InventoryItem $record): bool => $record->getKey() !== $this->getOwnerRecord()->getKey()),
            ])
            ->defaultSort('warehouse_id');
    }
}
