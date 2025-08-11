<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'أصناف الطلب';

    protected static ?string $modelLabel = 'صنف';

    protected static ?string $pluralModelLabel = 'الأصناف';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                // This is view-only, no form needed
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product.name')
            ->columns([
                Tables\Columns\TextColumn::make('product.name')
                    ->label('اسم المنتج')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('product.category.name')
                    ->label('الفئة')
                    ->searchable()
                    ->placeholder('غير محدد'),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('الكمية')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('price')
                    ->label('سعر الوحدة')
                    ->money('EGP')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total')
                    ->label('الإجمالي')
                    ->money('EGP')
                    ->sortable()
                    ->getStateUsing(function ($record) {
                        return $record->quantity * $record->price;
                    }),

                Tables\Columns\TextColumn::make('cost')
                    ->label('التكلفة')
                    ->money('EGP')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('notes')
                    ->label('ملاحظات')
                    ->limit(50)
                    ->placeholder('لا توجد ملاحظات')
                    ->tooltip(function ($record) {
                        return $record->notes ?: 'لا توجد ملاحظات';
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('product.category_id')
                    ->label('الفئة')
                    ->relationship('product.category', 'name')
                    ->preload(),
            ])
            ->headerActions([
                // View-only: no create action
            ])
            ->actions([
                // View-only: no edit or delete actions
            ])
            ->bulkActions([
                // View-only: no bulk actions
            ])
            ->defaultSort('id');
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
