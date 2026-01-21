<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class OrderReturnsRelationManager extends RelationManager
{
    protected static string $relationship = 'returns';

    protected static ?string $title = 'عمليات الإرجاع';

    protected static ?string $label = 'إرجاع';

    protected static ?string $pluralLabel = 'عمليات الإرجاع';

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
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('id')
                    ->label('رقم المرتجع')
                    ->sortable(),

                TextColumn::make('total_refund')
                    ->label('إجمالي الاسترجاع')
                    ->money('EGP')
                    ->sortable(),

                IconColumn::make('reverse_stock')
                    ->label('إعادة للمخزون')
                    ->boolean(),

                TextColumn::make('user.name')
                    ->label('المستخدم')
                    ->searchable(),

                TextColumn::make('shift.id')
                    ->label('الوردية')
                    ->sortable()
                    ->prefix('#'),

                TextColumn::make('created_at')
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
            ->recordActions([
                ViewAction::make()
                    ->url(fn($record) => route('filament.admin.resources.order-returns.view', ['record' => $record->id])),
            ])
            ->toolbarActions([
                // No bulk actions
            ])
            ->defaultSort('created_at', 'desc');
    }
}
