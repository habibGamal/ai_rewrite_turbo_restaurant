<?php

namespace App\Filament\Resources\InventoryItemResource\RelationManagers;

use App\Enums\InventoryMovementOperation;
use App\Enums\MovementReason;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class MovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'movements';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return 'حركات المخزون';
    }

    public static function getModelLabel(): string
    {
        return 'حركة مخزون';
    }

    public static function getPluralModelLabel(): string
    {
        return 'حركات المخزون';
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('operation')
                    ->label('نوع العملية')
                    ->badge(),
                Tables\Columns\TextColumn::make('quantity')
                    ->label('الكمية')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                Tables\Columns\TextColumn::make('reason')
                    ->label('السبب')
                    ->badge(),
                Tables\Columns\TextColumn::make('referenceable_type')
                    ->label('نوع المرجع')
                    ->badge(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('operation')
                    ->label('نوع العملية')
                    ->options(InventoryMovementOperation::class),
                Tables\Filters\SelectFilter::make('reason')
                    ->label('السبب')
                    ->options(MovementReason::class),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
