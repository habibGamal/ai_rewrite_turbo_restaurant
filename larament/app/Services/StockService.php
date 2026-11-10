<?php

namespace App\Services;

use App\Costing\CostingStrategy;
use App\DTOs\StockMovementDTO;
use App\Enums\InventoryMovementOperation;
use App\Enums\MovementReason;
use App\Exceptions\StockOperationException;
use App\Models\InventoryItem;
use App\Models\InventoryItemMovement;
use App\Models\Product;
use App\Models\Warehouse;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class StockService
{
    /**
     * Setting to allow or disallow operations with insufficient stock
     * Set to false to prevent stock movements that would result in negative inventory
     */
    public const ALLOW_INSUFFICIENT_STOCK = true;

    private InventoryDailyAggregationService $dailyAggregationService;

    public function __construct(InventoryDailyAggregationService $dailyAggregationService)
    {
        // Ensure we're using the Query Builder approach for better compatibility
        $this->dailyAggregationService = $dailyAggregationService;
    }

    /**
     * Add stock for multiple items
     *
     * @return Collection<int, InventoryItemMovement>
     */
    public function addStock(array $items, MovementReason $reason = MovementReason::MANUAL, ?Model $referenceable = null): Collection
    {
        return $this->processStock($items, InventoryMovementOperation::IN, $reason, $referenceable);
    }

    /**
     * Remove stock for multiple items
     *
     * @return Collection<int, InventoryItemMovement>
     */
    public function removeStock(array $items, MovementReason $reason = MovementReason::MANUAL, ?Model $referenceable = null): Collection
    {
        return $this->processStock($items, InventoryMovementOperation::OUT, $reason, $referenceable);
    }

    /** @return Collection<int, InventoryItemMovement> */
    public function transfer(int $fromWarehouseId, int $toWarehouseId, array $items, Model $ref): Collection
    {
        if ($fromWarehouseId === $toWarehouseId) {
            throw ValidationException::withMessages([
                'to_warehouse_id' => 'يجب أن يختلف المخزن المصدر عن المخزن المستلم.',
            ]);
        }

        return DB::transaction(function () use ($fromWarehouseId, $toWarehouseId, $items, $ref): Collection {
            shouldDayBeOpen();
            $this->validateItems($items);
            $costs = Product::whereIn('id', array_column($items, 'product_id'))
                ->orderBy('id')->lockForUpdate()->pluck('cost', 'id');
            $source = [];
            $destination = [];
            foreach ($items as $item) {
                $line = [
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_cost' => (float) $costs->get($item['product_id'], 0),
                ];
                $source[] = $line + ['warehouse_id' => $fromWarehouseId];
                $destination[] = $line + ['warehouse_id' => $toWarehouseId];
            }

            $out = $this->removeStock($source, MovementReason::TRANSFER_OUT, $ref);
            $in = $this->addStock($destination, MovementReason::TRANSFER_IN, $ref);

            return $out->concat($in)->values();
        });
    }

    /**
     * Legacy method for backward compatibility
     *
     * @deprecated Use addStock() or removeStock() instead
     */
    public function processMultipleItems(array $items, string $operation = 'add', string|MovementReason $reason = 'bulk_operation', $referenceable = null): bool
    {
        $movementOperation = $operation === 'add' ? InventoryMovementOperation::IN : InventoryMovementOperation::OUT;
        $movementReason = $reason instanceof MovementReason
            ? $reason
            : (MovementReason::tryFrom($reason) ?? MovementReason::MANUAL);

        return $this->processStock($items, $movementOperation, $movementReason, $referenceable)->isNotEmpty();
    }

    /**
     * Internal method that processes stock movements
     */
    private function processStock(array $items, InventoryMovementOperation $operation, MovementReason $reason, ?Model $referenceable): Collection
    {
        $transactionStarted = false;

        try {
            shouldDayBeOpen();

            DB::beginTransaction();
            $transactionStarted = true;

            $items = $this->normalizeItems($items);
            $this->validateProducts($items);

            if ($operation->isOutgoing()) {
                $this->validateStockAvailabilityOrThrow($items);
            }

            $existingInventory = $this->getExistingInventory($items);
            $stockMovements = $this->prepareStockMovements($items, $operation, $reason, $referenceable, $existingInventory);

            $movements = $this->recordMovements($stockMovements);
            $this->updateDailyAggregations($stockMovements);
            // $this->logOperation($items, operation: $operation, $reason);

            DB::commit();

            return $movements;

        } catch (Throwable $e) {
            // Only roll back our own transaction, never the caller's
            if ($transactionStarted) {
                DB::rollBack();
            }

            Log::error('Failed to process stock movement: '.$e->getMessage(), [
                'operation' => $operation->value,
                'reason' => $reason->value,
                'items_count' => count($items),
            ]);

            throw new StockOperationException('فشل في تحديث المخزون: '.$e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float|int, warehouse_id?: ?int, unit_cost?: ?float, total_cost?: ?float}>  $items
     * @return array<int, array{product_id: int, warehouse_id: int, quantity: float, unit_cost: ?float, total_cost: ?float}>
     */
    private function normalizeItems(array $items): array
    {
        $this->validateItems($items);
        $costs = Product::whereIn('id', array_column($items, 'product_id'))->orderBy('id')->lockForUpdate()->pluck('cost', 'id');
        $defaultWarehouseId = Warehouse::default()->id;
        $grouped = [];

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $warehouseId = (int) ($item['warehouse_id'] ?? $defaultWarehouseId);
            $quantity = (float) $item['quantity'];
            $hasExplicitTotal = isset($item['total_cost']);
            $hasExplicitUnit = isset($item['unit_cost']);
            $unitCost = $hasExplicitUnit
                ? (float) $item['unit_cost']
                : ($hasExplicitTotal && $quantity > 0 ? ((float) $item['total_cost'] / $quantity) : (float) $costs->get($productId, 0));
            $lineTotal = $hasExplicitTotal ? (float) $item['total_cost'] : ($quantity * $unitCost);
            $key = $productId.':'.$warehouseId;

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'quantity' => 0.0,
                    'value' => 0.0,
                    'unit_value' => 0.0,
                    'has_cost' => false,
                    'has_total_cost' => false,
                ];
            }
            $grouped[$key]['quantity'] += $quantity;
            $grouped[$key]['value'] += $lineTotal;
            $grouped[$key]['unit_value'] += $quantity * $unitCost;
            $grouped[$key]['has_cost'] = $grouped[$key]['has_cost'] || $hasExplicitUnit || $hasExplicitTotal;
            $grouped[$key]['has_total_cost'] = $grouped[$key]['has_total_cost'] || $hasExplicitTotal;
        }

        foreach ($grouped as $key => $item) {
            $grouped[$key]['unit_cost'] = $item['has_cost'] ? round($item['unit_value'] / $item['quantity'], 4) : null;
            $grouped[$key]['total_cost'] = $item['has_total_cost'] ? round($item['value'], 2) : null;
            $grouped[$key]['quantity'] = round($item['quantity'], 3);
            unset($grouped[$key]['value'], $grouped[$key]['unit_value'], $grouped[$key]['has_cost'], $grouped[$key]['has_total_cost']);
        }

        return array_values($grouped);
    }

    /**
     * Validate items array structure
     */
    private function validateItems(array $items): void
    {
        if (empty($items)) {
            throw new InvalidArgumentException('Items array cannot be empty');
        }

        foreach ($items as $index => $item) {
            if (! isset($item['product_id']) || ! isset($item['quantity'])) {
                throw new InvalidArgumentException("Item at index {$index} must have 'product_id' and 'quantity' keys");
            }

            if (! is_numeric($item['product_id']) || ! is_numeric($item['quantity'])) {
                throw new InvalidArgumentException("Item at index {$index} must have numeric 'product_id' and 'quantity' values");
            }

            if ($item['quantity'] <= 0) {
                throw new InvalidArgumentException("Item at index {$index} must have positive quantity");
            }
        }
    }

    /**
     * Validate that all products exist
     */
    private function validateProducts(array $items): void
    {
        $productIds = array_column($items, 'product_id');
        $existingProducts = Product::whereIn('id', $productIds)->pluck('id')->toArray();
        $missingProducts = array_diff($productIds, $existingProducts);

        if (! empty($missingProducts)) {
            throw new InvalidArgumentException('Products not found: '.implode(', ', $missingProducts));
        }
    }

    /**
     * Validate stock availability and throw exception if insufficient
     */
    private function validateStockAvailabilityOrThrow(array $items): void
    {
        // Skip validation if insufficient stock operations are allowed
        if (self::ALLOW_INSUFFICIENT_STOCK) {
            return;
        }

        $insufficientItems = $this->validateStockAvailability($items);

        if (! empty($insufficientItems)) {
            $errorMessage = 'Insufficient stock for: '.
                implode(', ', array_column($insufficientItems, 'product_name'));
            throw new InvalidArgumentException($errorMessage);
        }
    }

    /**
     * Get existing inventory items
     */
    private function getExistingInventory(array $items): Collection
    {
        return InventoryItem::where(function ($query) use ($items) {
            foreach ($items as $item) {
                $query->orWhere(fn ($pair) => $pair->where('product_id', $item['product_id'])
                    ->where('warehouse_id', $item['warehouse_id']));
            }
        })
            ->orderBy('product_id')
            ->orderBy('warehouse_id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (InventoryItem $item) => $item->product_id.':'.$item->warehouse_id);
    }

    /**
     * Prepare stock movement DTOs
     */
    private function prepareStockMovements(
        array $items,
        InventoryMovementOperation $operation,
        MovementReason $reason,
        $referenceable,
        Collection $existingInventory
    ): array {
        $movements = [];

        foreach ($items as $item) {
            $productId = $item['product_id'];
            $quantity = $item['quantity'];
            $existingItem = $existingInventory->get($productId.':'.$item['warehouse_id']);

            $movements[] = new StockMovementDTO(
                productId: $productId,
                quantity: $quantity,
                operation: $operation,
                reason: $reason,
                referenceable: $referenceable,
                existingInventoryItem: $existingItem,
                warehouseId: $item['warehouse_id'],
                unitCost: $item['unit_cost'],
                userId: auth()->id(),
                totalCost: $item['total_cost'] ?? null,
            );
        }

        return $movements;
    }

    /**
     * Execute stock movements (updates and inserts)
     */
    private function executeStockMovements(array $stockMovements): void
    {
        $inventoryUpdates = [];
        $newInventoryItems = [];

        /** @var StockMovementDTO $movement */
        foreach ($stockMovements as $movement) {
            if ($movement->existingInventoryItem) {
                $inventoryUpdates[] = $movement;
            } else {
                $newInventoryItems[] = $movement;
            }
        }

        $this->updateExistingInventory($inventoryUpdates);
        $this->createNewInventoryItems($newInventoryItems);
    }

    /**
     * Update existing inventory items
     */
    private function updateExistingInventory(array $movements): void
    {
        if (empty($movements)) {
            return;
        }

        /** @var StockMovementDTO $movement */
        foreach ($movements as $movement) {
            if ($movement->operation->isIncoming()) {
                InventoryItem::where('id', $movement->existingInventoryItem->id)
                    ->increment('quantity', $movement->quantity);
            } else {
                InventoryItem::where('id', $movement->existingInventoryItem->id)
                    ->decrement('quantity', $movement->quantity);
            }
        }
    }

    /**
     * Create new inventory items
     */
    private function createNewInventoryItems(array $movements): void
    {
        if (empty($movements)) {
            return;
        }

        $items = [];
        /** @var StockMovementDTO $movement */
        foreach ($movements as $movement) {
            $quantity = $movement->operation->isIncoming()
                ? $movement->quantity
                : -$movement->quantity;

            $items[] = [
                'product_id' => $movement->productId,
                'warehouse_id' => $movement->warehouseId,
                'quantity' => $quantity,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        InventoryItem::insert($items);
    }

    /**
     * Record inventory movements
     */
    private function recordMovements(array $stockMovements): Collection
    {
        $movements = collect();
        /** @var StockMovementDTO $movement */
        foreach ($stockMovements as $movement) {
            $product = Product::lockForUpdate()->findOrFail($movement->productId);
            $unitCost = $movement->unitCost ?? (float) $product->cost;
            $totalCost = ($movement->operation->isIncoming() && $movement->totalCost !== null)
                ? round($movement->totalCost, 2)
                : round($movement->quantity * $unitCost, 2);
            $record = new InventoryItemMovement([
                'product_id' => $movement->productId,
                'warehouse_id' => $movement->warehouseId,
                'business_date' => $this->dailyAggregationService->currentBusinessDate()->toDateString(),
                'operation' => $movement->operation,
                'quantity' => $movement->quantity,
                'reason' => $movement->reason,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'user_id' => $movement->userId,
            ]);
            if ($movement->referenceable) {
                $record->referenceable()->associate($movement->referenceable);
            }
            $record->save();
            $record->setRelation('product', $product);
            $this->costMovement($record);
            $this->executeStockMovements([$movement]);
            $movements->push($record);
        }

        return $movements;
    }

    private function costMovement(InventoryItemMovement $movement): void
    {
        if (in_array($movement->reason, [MovementReason::TRANSFER_IN, MovementReason::TRANSFER_OUT], true)) {
            return;
        }

        $strategy = app(CostingStrategy::class);
        if ($movement->operation->isOutgoing()) {
            $strategy->consume($movement);

            return;
        }

        if ($movement->reason === MovementReason::ORDER_RETURN) {
            $original = $movement->referenceable_id === null ? null : InventoryItemMovement::query()
                ->where('referenceable_type', $movement->referenceable_type)
                ->where('referenceable_id', $movement->referenceable_id)
                ->where('product_id', $movement->product_id)
                ->where('warehouse_id', $movement->warehouse_id)
                ->where('reason', MovementReason::ORDER)
                ->where('operation', InventoryMovementOperation::OUT)
                ->orderBy('id')
                ->first();

            if ($original !== null) {
                $strategy->reverse($original, $movement);

                return;
            }

            $movement->unit_cost = $strategy->currentUnitCost($movement->product);
            $movement->total_cost = null;
        }

        $strategy->receive($movement);
    }

    /**
     * Update daily aggregations for the affected products (optimized)
     */
    private function updateDailyAggregations(array $stockMovements): void
    {
        try {
            $today = Carbon::today();
            $productIds = array_unique(array: array_map(fn ($movement) => $movement->productId, $stockMovements));

            // Then aggregate movements for better performance
            $this->dailyAggregationService->aggregateMultipleMovements($productIds, $today);

        } catch (Exception $e) {
            // Log error but don't fail the transaction as daily aggregation is not critical
            Log::error('Failed to update daily aggregations: '.$e->getMessage(), [
                'product_ids' => array_unique(array: array_map(fn ($movement) => $movement->productId, $stockMovements)),
            ]);
        }
    }

    /**
     * Log the operation
     */
    private function logOperation(array $items, InventoryMovementOperation $operation, MovementReason $reason): void
    {
        $totalItems = count($items);
        $operationType = $operation->isIncoming() ? 'add' : 'remove';

        Log::info("Bulk stock {$operationType}: {$totalItems} items processed", [
            'operation' => $operation->value,
            'reason' => $reason->value,
            'items_count' => $totalItems,
        ]);
    }

    /**
     * Get current stock for a product
     */
    public function getCurrentStock(int $productId, ?int $warehouseId = null): float
    {
        return (float) InventoryItem::where('product_id', $productId)
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->sum('quantity');
    }

    /**
     * Check if product has sufficient stock
     */
    public function hasSufficientStock(int $productId, float $requiredQuantity): bool
    {
        return $this->getCurrentStock($productId) >= $requiredQuantity;
    }

    /**
     * Validate stock availability for multiple items using bulk operations
     */
    public function validateStockAvailability(array $items): array
    {
        $productIds = array_column($items, 'product_id');

        // Get all inventory items for the products in a single query
        $inventoryItems = InventoryItem::whereIn('product_id', $productIds)
            ->selectRaw('product_id, SUM(quantity) as quantity')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        // Get product names for insufficient items in a single query
        $products = Product::whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $insufficientItems = [];

        foreach ($items as $item) {
            $productId = $item['product_id'];
            $requiredQuantity = $item['quantity'];

            $availableQuantity = $inventoryItems->has($productId)
                ? $inventoryItems[$productId]->quantity
                : 0;

            if ($availableQuantity < $requiredQuantity) {
                $product = $products->get($productId);
                $insufficientItems[] = [
                    'product_id' => $productId,
                    'product_name' => $product ? $product->name : 'Unknown',
                    'required_quantity' => $requiredQuantity,
                    'available_quantity' => $availableQuantity,
                ];
            }
        }

        return $insufficientItems;
    }
}
