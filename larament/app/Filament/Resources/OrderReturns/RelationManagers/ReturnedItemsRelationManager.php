<?php

namespace App\Filament\Resources\OrderReturns\RelationManagers;

use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

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
            ->recordTitleAttribute('orderItem.product.name')
            ->columns([
                TextColumn::make('orderItem.product.name')
                    ->label('المنتج')
                    ->searchable(),

                TextColumn::make('quantity')
                    ->label('الكمية المرتجعة')
                    ->numeric(),

                TextColumn::make('refund_amount')
                    ->label('مبلغ الاسترجاع')
                    ->money('EGP'),

                TextColumn::make('orderItem.quantity')
                    ->label('الكمية الأصلية')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('orderItem.price')
                    ->label('السعر الأصلي')
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
