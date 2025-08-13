<?php

namespace App\Filament\Resources\InventoryItemResource\RelationManagers;

use App\Enums\InventoryMovementOperation;
use App\Enums\MovementReason;
use Filament\Forms;
use Filament\Forms\Components\DateTimePicker;
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

    protected $queryString = [
        'tableFilters',
    ];

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
                Tables\Columns\TextColumn::make('referenceable_id')
                    ->label('رقم المرجع'),
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
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        DateTimePicker::make('created_from'),
                        DateTimePicker::make('created_until')
                            ->default(now()),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn(Builder $query, $date): Builder => $query->where('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn(Builder $query, $date): Builder => $query->where('created_at', '<=', $date),
                            );
                    })
            ])
            ->actions([
                Tables\Actions\Action::make('view_reference')
                    ->label('عرض المرجع')
                    ->icon('heroicon-o-eye')
                    ->url(fn($record) => match ($record->referenceable_type) {
                        \App\Models\Order::class => route('filament.admin.resources.orders.view', ['record' => $record->referenceable_id]),
                        \App\Models\PurchaseInvoice::class => route('filament.admin.resources.purchase-invoices.view', ['record' => $record->referenceable_id]),
                        \App\Models\ReturnPurchaseInvoice::class => route('filament.admin.resources.return-purchase-invoices.view', ['record' => $record->referenceable_id]),
                        \App\Models\Waste::class => route('filament.admin.resources.wastes.view', ['record' => $record->referenceable_id]),
                        \App\Models\Stocktaking::class => route('filament.admin.resources.stocktakings.view', ['record' => $record->referenceable_id]),
                        default => null,
                    })
                    ->openUrlInNewTab()
                    ->color('primary'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
