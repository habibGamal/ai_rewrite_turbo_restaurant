<?php

namespace App\Filament\Resources\InventoryItems\RelationManagers;

use App\Enums\InventoryMovementOperation;
use App\Enums\MovementReason;
use App\Models\InventoryItemMovement;
use App\Services\Reports\Inventory\ItemLedgerReportService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->columns([
                TextColumn::make('operation')
                    ->label('نوع العملية')
                    ->badge(),
                TextColumn::make('quantity')
                    ->label('الكمية')
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),
                TextColumn::make('unit_cost')
                    ->label('تكلفة الوحدة')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('total_cost')
                    ->label('إجمالي التكلفة')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('reason')
                    ->label('السبب')
                    ->badge(),
                TextColumn::make('referenceable_type')
                    ->label('نوع المرجع')
                    ->formatStateUsing(fn (?string $state, InventoryItemMovement $record): string => ItemLedgerReportService::documentTypeLabel($state, $record->reason))
                    ->badge(),
                TextColumn::make('referenceable_id')
                    ->label('رقم المرجع')
                    ->placeholder('—'),
                TextColumn::make('user.name')
                    ->label('المستخدم')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('operation')
                    ->label('نوع العملية')
                    ->options(InventoryMovementOperation::class),
                SelectFilter::make('reason')
                    ->label('السبب')
                    ->options(MovementReason::class),
                Filter::make('created_at')
                    ->schema([
                        DateTimePicker::make('created_from'),
                        DateTimePicker::make('created_until')
                            ->default(now()),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (Builder $query, $date): Builder => $query->where('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (Builder $query, $date): Builder => $query->where('created_at', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                Action::make('view_reference')
                    ->label('عرض المرجع')
                    ->icon('heroicon-o-eye')
                    ->visible(fn (InventoryItemMovement $record): bool => ItemLedgerReportService::documentUrl($record->referenceable_type, $record->referenceable_id) !== null)
                    ->url(fn (InventoryItemMovement $record): ?string => ItemLedgerReportService::documentUrl($record->referenceable_type, $record->referenceable_id))
                    ->openUrlInNewTab()
                    ->color('primary'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
