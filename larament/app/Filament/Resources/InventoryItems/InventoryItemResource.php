<?php

namespace App\Filament\Resources\InventoryItems;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use App\Enums\ProductType;
use Filament\Actions\ViewAction;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use App\Filament\Resources\InventoryItems\RelationManagers\MovementsRelationManager;
use App\Filament\Resources\InventoryItems\Pages\ListInventoryItems;
use App\Filament\Resources\InventoryItems\Pages\ViewInventoryItem;
use App\Filament\Resources\InventoryItemResource\Pages;
use App\Filament\Resources\InventoryItemResource\RelationManagers;
use App\Models\InventoryItem;
use Filament\Forms\Components\Select;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use \App\Filament\Traits\AdminAccess;

class InventoryItemResource extends Resource
{
    use AdminAccess;

    protected static ?string $model = InventoryItem::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-archive-box';

    protected static string | \UnitEnum | null $navigationGroup = 'إدارة المخزون';

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
                TextColumn::make('product.name')
                    ->label('اسم المنتج')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product.category.name')
                    ->label('الفئة')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label('الكمية')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn($record): string => match (true) {
                        $record->quantity > ($record->product->min_stock * 2) => 'success',
                        $record->quantity > $record->product->min_stock => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('product.min_stock')
                    ->label('الحد الأدنى للمخزون')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color('info'),
                TextColumn::make('product.unit')
                    ->label('الوحدة')
                    ->badge(),
                TextColumn::make('product.type')
                    ->label('نوع المنتج')
                    ->badge()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('تاريخ التحديث')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('product.category_id')
                    ->label('الفئة')
                    ->relationship('product.category', 'name'),
                Filter::make('product_type')
                    ->label('نوع المنتج')
                    ->schema([
                        Select::make('type')
                            ->label('نوع المنتج')
                            ->options(ProductType::toSelectArray())
                            ->placeholder('اختر نوع المنتج'),
                    ])
                    ->query(
                        fn(Builder $query, array $data) => $query
                            ->when(
                                $data['type'] ?? null,
                                fn(Builder $query) => $query->whereHas('product', fn(Builder $productQuery) => $productQuery->where('type', $data['type'] ?? null))
                            )
                    ),
                Filter::make('low_stock')
                    ->label('مخزون منخفض')
                    ->query(fn($query) => $query->whereRaw('quantity <= (SELECT min_stock FROM products WHERE products.id = inventory_items.product_id)')),
                Filter::make('critical_stock')
                    ->label('مخزون حرج')
                    ->query(fn($query) => $query->whereRaw('quantity < (SELECT min_stock FROM products WHERE products.id = inventory_items.product_id)')),
                Filter::make('out_of_stock')
                    ->label('نفد المخزون')
                    ->query(fn($query) => $query->where('quantity', '<=', 0)),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('عرض التفاصيل'),
            ])
            ->toolbarActions([
                // No bulk actions - read-only resource
            ])
            ->defaultSort('quantity', 'asc'); // Show low stock items first
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('معلومات المنتج')
                    ->schema([
                        TextEntry::make('product.name')
                            ->label('اسم المنتج'),
                        TextEntry::make('product.category.name')
                            ->label('الفئة'),
                        TextEntry::make('product.unit')
                            ->label('الوحدة'),
                        TextEntry::make('product.cost')
                            ->label('التكلفة'),
                        TextEntry::make('product.type')
                            ->label('نوع المنتج'),
                    ])
                    ->columns(3)
            ]);
    }



    public static function getRelations(): array
    {
        return [
            MovementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventoryItems::route('/'),
            'view' => ViewInventoryItem::route('/{record}'),
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
