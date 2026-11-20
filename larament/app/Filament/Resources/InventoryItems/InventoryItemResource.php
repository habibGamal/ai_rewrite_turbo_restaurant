<?php

namespace App\Filament\Resources\InventoryItems;

use App\Enums\ProductType;
use App\Filament\Resources\InventoryItems\Pages\ListInventoryItems;
use App\Filament\Resources\InventoryItems\Pages\ViewInventoryItem;
use App\Filament\Resources\InventoryItems\RelationManagers\MovementsRelationManager;
use App\Filament\Resources\InventoryItems\RelationManagers\UsedInProductsRelationManager;
use App\Filament\Resources\InventoryItems\RelationManagers\WarehouseBalancesRelationManager;
use App\Models\InventoryItem;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InventoryItemResource extends Resource
{
    protected static ?string $model = InventoryItem::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المخزون';

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return 'عنصر مخزون';
    }

    public static function getPluralModelLabel(): string
    {
        return 'عناصر المخزون';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['product.category', 'warehouse']);
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
                TextColumn::make('warehouse.name')
                    ->label('المخزن')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label('الكمية')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn ($record): string => match (true) {
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
                SelectFilter::make('warehouse_id')
                    ->label('المخزن')
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload(),
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
                        fn (Builder $query, array $data) => $query
                            ->when(
                                $data['type'] ?? null,
                                fn (Builder $query) => $query->whereHas('product', fn (Builder $productQuery) => $productQuery->where('type', $data['type'] ?? null))
                            )
                    ),
                Filter::make('low_stock')
                    ->label('مخزون منخفض')
                    ->query(fn ($query) => $query->whereRaw('quantity <= (SELECT min_stock FROM products WHERE products.id = inventory_items.product_id)')),
                Filter::make('critical_stock')
                    ->label('مخزون حرج')
                    ->query(fn ($query) => $query->whereRaw('quantity < (SELECT min_stock FROM products WHERE products.id = inventory_items.product_id)')),
                Filter::make('out_of_stock')
                    ->label('نفد المخزون')
                    ->query(fn ($query) => $query->where('quantity', '<=', 0)),
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
                        TextEntry::make('warehouse.name')
                            ->label('المخزن')
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('quantity')
                            ->label('الكمية بالمخزن')
                            ->numeric(decimalPlaces: 3)
                            ->badge()
                            ->color(fn (InventoryItem $record): string => match (true) {
                                $record->quantity > ($record->product->min_stock * 2) => 'success',
                                $record->quantity > $record->product->min_stock => 'warning',
                                default => 'danger',
                            }),
                        TextEntry::make('total_stock')
                            ->label('إجمالي المخزون بكل المخازن')
                            ->state(fn (InventoryItem $record): float => (float) InventoryItem::query()
                                ->where('product_id', $record->product_id)
                                ->sum('quantity'))
                            ->numeric(decimalPlaces: 3)
                            ->badge()
                            ->color('info'),
                        TextEntry::make('product.min_stock')
                            ->label('الحد الأدنى للمخزون')
                            ->numeric(),
                        TextEntry::make('product.unit')
                            ->label('الوحدة'),
                        TextEntry::make('product.cost')
                            ->label('التكلفة'),
                        TextEntry::make('product.type')
                            ->label('نوع المنتج'),
                    ])
                    ->columns(3),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            MovementsRelationManager::class,
            WarehouseBalancesRelationManager::class,
            UsedInProductsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventoryItems::route('/'),
            'view' => ViewInventoryItem::route('/{record}'),
        ];
    }

    public static function getCreateAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }

    public static function getViewAuthorizationResponse(Model $record): Response
    {
        return Response::allow();
    }
}
