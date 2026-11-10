<?php

namespace App\Filament\Resources\StockTransfers\RelationManagers;

use App\Models\Product;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'أصناف التحويل';

    protected static ?string $modelLabel = 'صنف محول';

    protected static ?string $pluralModelLabel = 'أصناف محولة';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product.name')
            ->columns([
                TextColumn::make('product.name')
                    ->label('المنتج')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('quantity')
                    ->label('الكمية')
                    ->numeric(decimalPlaces: 3)
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('unit_cost')
                    ->label('تكلفة الوحدة')
                    ->money('EGP')
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('total_cost')
                    ->label('الإجمالي')
                    ->money('EGP')
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('product.unit')
                    ->label('الوحدة')
                    ->formatStateUsing(fn (?string $state): string => Product::formatUnit($state)),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('id', 'asc');
    }
}
