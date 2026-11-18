<?php

namespace App\Filament\Resources\InventoryItems\RelationManagers;

use App\Enums\MovementReason;
use App\Enums\OrderStatus;
use App\Models\InventoryItem;
use App\Models\InventoryItemMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\Shift;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockWarehouseResolver;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'product.category.parent.warehouse',
                'product.category.warehouse',
                'product.warehouse',
            ]))
            ->columns([
                TextColumn::make('product.name')
                    ->label('المنتج المصنع')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product.category.name')
                    ->label('الفئة')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('issue_warehouse')
                    ->label('مخزن الصرف')
                    ->badge()
                    ->color('gray')
                    ->state(function (ProductComponent $record): ?string {
                        $product = $record->product;
                        if (! $product instanceof Product) {
                            return null;
                        }

                        return Warehouse::query()->find($product->stockWarehouseId())?->name;
                    }),
                TextColumn::make('quantity')
                    ->label('الكمية لكل وحدة')
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),
                TextColumn::make('product.unit')
                    ->label('الوحدة')
                    ->badge(),
                TextColumn::make('total_consumed')
                    ->label('إجمالي الاستهلاك')
                    ->numeric(decimalPlaces: 3)
                    ->state(fn (ProductComponent $record): float => $this->consumedInOwnerWarehouse($record)),
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
                                    ->mapWithKeys(function (Shift $shift) {
                                        $userLabel = $shift->user instanceof User ? $shift->user->name : 'غير محدد';
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

    protected function consumedInOwnerWarehouse(ProductComponent $record): float
    {
        $product = $record->product;
        if (! $product instanceof Product) {
            return 0.0;
        }

        /** @var InventoryItem $ownerRecord */
        $ownerRecord = $this->getOwnerRecord();
        $warehouseId = (int) $ownerRecord->warehouse_id;
        $componentId = (int) $ownerRecord->product_id;
        $shiftIds = $this->getSelectedShiftIds();

        /** @var Collection<int, OrderItem> $orderItems */
        $orderItems = $product->orderItems()
            ->with('order')
            ->whereHas('order', function (Builder $query) use ($shiftIds): void {
                $query->where('status', OrderStatus::COMPLETED)
                    ->when(! empty($shiftIds), fn (Builder $shiftQuery) => $shiftQuery->whereIn('shift_id', $shiftIds));
            })
            ->get();

        if ($orderItems->isEmpty()) {
            return 0.0;
        }

        $orderIds = $orderItems->pluck('order_id')->unique()->values()->all();

        $movementWarehousesByOrder = InventoryItemMovement::query()
            ->where('referenceable_type', Order::class)
            ->whereIn('referenceable_id', $orderIds)
            ->where('product_id', $componentId)
            ->where('reason', MovementReason::ORDER)
            ->get(['referenceable_id', 'warehouse_id'])
            ->groupBy('referenceable_id')
            ->map(fn (Collection $group): array => $group->pluck('warehouse_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());

        $resolver = app(StockWarehouseResolver::class);

        $totalSold = $orderItems
            ->filter(function (OrderItem $orderItem) use ($movementWarehousesByOrder, $warehouseId, $product, $resolver): bool {
                $warehouses = $movementWarehousesByOrder->get($orderItem->order_id);
                $order = $orderItem->order instanceof Order ? $orderItem->order : null;

                if ($warehouses === null) {
                    return $resolver->forOrder($product, $order) === $warehouseId;
                }

                if (count($warehouses) === 1) {
                    return $warehouses[0] === $warehouseId;
                }

                return in_array($warehouseId, $warehouses, true)
                    && $resolver->forOrder($product, $order) === $warehouseId;
            })
            ->sum(fn (OrderItem $orderItem): float => (float) $orderItem->quantity);

        return (float) $totalSold * (float) $record->quantity;
    }
}
