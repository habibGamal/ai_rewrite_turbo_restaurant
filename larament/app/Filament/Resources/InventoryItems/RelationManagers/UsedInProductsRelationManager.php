<?php

namespace App\Filament\Resources\InventoryItems\RelationManagers;

use App\Enums\OrderStatus;
use App\Models\Shift;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsedInProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'usedInProducts';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return 'يُستخدم في المنتجات';
    }

    public static function getModelLabel(): string
    {
        return 'منتج مصنع';
    }

    public static function getPluralModelLabel(): string
    {
        return 'المنتجات المصنعة';
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product.category']))
            ->columns([
                TextColumn::make('product.name')
                    ->label('المنتج المصنع')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product.category.name')
                    ->label('الفئة')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label('الكمية لكل وحدة')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('product.unit')
                    ->label('الوحدة')
                    ->badge(),
                TextColumn::make('total_consumed')
                    ->label('إجمالي الاستهلاك')
                    ->numeric(decimalPlaces: 2)
                    ->state(function ($record) {
                        $shiftIds = $this->getSelectedShiftIds();

                        $query = $record->product->orderItems()
                            ->whereHas('order', fn (Builder $q) => $q->where('status', OrderStatus::COMPLETED));

                        if (! empty($shiftIds)) {
                            $query->whereHas('order', fn (Builder $q) => $q->whereIn('shift_id', $shiftIds));
                        }

                        $totalSold = $query->sum('quantity');

                        return $totalSold * $record->quantity;
                    }),
                TextColumn::make('product.cost')
                    ->label('التكلفة')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
            ])
            ->filters([
                Filter::make('shift_ids')
                    ->label('الشفتات')
                    ->schema([
                        Select::make('values')
                            ->label('الشفتات')
                            ->options(function () {
                                return Shift::with('user')
                                    ->orderBy('start_at', 'desc')
                                    ->get()
                                    ->mapWithKeys(function ($shift) {
                                        $userLabel = $shift->user ? $shift->user->name : 'غير محدد';
                                        $startDate = $shift->start_at ? $shift->start_at->format('d/m/Y H:i') : 'غير محدد';
                                        $endDate = $shift->end_at ? $shift->end_at->format('d/m/Y H:i') : 'لم ينته';

                                        return [
                                            $shift->id => "شفت #{$shift->id} - {$userLabel} ({$startDate} - {$endDate})",
                                        ];
                                    });
                            })
                            ->searchable()
                            ->placeholder('اختر الشفتات')
                            ->multiple()
                            ->preload(),
                    ])
                    ->query(fn (Builder $query) => $query),
            ])
            ->defaultSort('product.name');
    }

    protected function getSelectedShiftIds(): array
    {
        $filterData = $this->tableFilters['shift_ids'] ?? [];

        return $filterData['values'] ?? $filterData['shift_ids'] ?? [];
    }
}
