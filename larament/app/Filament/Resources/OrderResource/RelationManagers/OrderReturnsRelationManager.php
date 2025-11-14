<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class OrderReturnsRelationManager extends RelationManager
{
    protected static string $relationship = 'returns';

    protected static ?string $title = 'عمليات الإرجاع';

    protected static ?string $label = 'إرجاع';

    protected static ?string $pluralLabel = 'عمليات الإرجاع';

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
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('رقم المرتجع')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_refund')
                    ->label('إجمالي الاسترجاع')
                    ->money('EGP')
                    ->sortable(),

                Tables\Columns\IconColumn::make('reverse_stock')
                    ->label('إعادة للمخزون')
                    ->boolean(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('المستخدم')
                    ->searchable(),

                Tables\Columns\TextColumn::make('shift.id')
                    ->label('الوردية')
                    ->sortable()
                    ->prefix('#'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                // No actions - returns created via main action
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->url(fn($record) => route('filament.admin.resources.order-returns.view', ['record' => $record->id])),
            ])
            ->bulkActions([
                // No bulk actions
            ])
            ->defaultSort('created_at', 'desc');
    }
}
