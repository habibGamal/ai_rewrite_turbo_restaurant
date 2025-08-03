<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryItemResource\Pages;
use App\Filament\Resources\InventoryItemResource\RelationManagers;
use App\Models\InventoryItem;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InventoryItemResource extends Resource
{
    protected static ?string $model = InventoryItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationGroup = 'إدارة المخزون';

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return 'عنصر مخزون';
    }

    public static function getPluralModelLabel(): string
    {
        return 'عناصر المخزون';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('product.name')
                    ->label('اسم المنتج')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('product.category.name')
                    ->label('الفئة')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('quantity')
                    ->label('الكمية')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn(string $state): string => match (true) {
                        $state > 50 => 'success',
                        $state > 20 => 'warning',
                        default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('product.unit')
                    ->label('الوحدة')
                    ->badge(),
                Tables\Columns\TextColumn::make('product.type')
                    ->label('نوع المنتج')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('تاريخ التحديث')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('product.category_id')
                    ->label('الفئة')
                    ->relationship('product.category', 'name'),
                Tables\Filters\Filter::make('product_type')
                    ->label('نوع المنتج')
                    ->form([
                        Select::make('type')
                            ->label('نوع المنتج')
                            ->options(\App\Enums\ProductType::toSelectArray())
                            ->placeholder('اختر نوع المنتج'),
                    ])
                    ->query(
                        fn(Builder $query, array $data) => $query
                            ->when(
                                $data['type'] ?? null,
                                fn(Builder $query) => $query->whereHas('product', fn(Builder $productQuery) => $productQuery->where('type', $data['type'] ?? null))
                            )
                    ),
                Tables\Filters\Filter::make('low_stock')
                    ->label('مخزون منخفض')
                    ->query(fn($query) => $query->where('quantity', '<=', 20)),
                Tables\Filters\Filter::make('out_of_stock')
                    ->label('نفد المخزون')
                    ->query(fn($query) => $query->where('quantity', '<=', 0)),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('عرض التفاصيل'),
            ])
            ->bulkActions([
                // No bulk actions - read-only resource
            ])
            ->defaultSort('quantity', 'asc'); // Show low stock items first
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\MovementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryItems::route('/'),
            'view' => Pages\ViewInventoryItem::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canView($record): bool
    {
        return true;
    }
}
