<?php

namespace App\Filament\Resources\OrderReturnResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ReturnedItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'أصناف المرتجع';

    protected static ?string $label = 'صنف';

    protected static ?string $pluralLabel = 'أصناف';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                // View-only
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('orderItem.product.name')
            ->columns([
                Tables\Columns\TextColumn::make('orderItem.product.name')
                    ->label('المنتج')
                    ->searchable(),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('الكمية المرتجعة')
                    ->numeric(),

                Tables\Columns\TextColumn::make('refund_amount')
                    ->label('مبلغ الاسترجاع')
                    ->money('EGP'),

                Tables\Columns\TextColumn::make('orderItem.quantity')
                    ->label('الكمية الأصلية')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('orderItem.price')
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
            ->actions([
                // No actions
            ])
            ->bulkActions([
                // No bulk actions
            ]);
    }
}
