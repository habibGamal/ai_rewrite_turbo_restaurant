<?php

namespace App\Services\Orders;

use App\Enums\MovementReason;
use App\Enums\ProductType;
use App\Models\InventoryItemMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Services\StockService;
use App\Services\StockWarehouseResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class OrderStockConversionService
{
    public function __construct(
        private readonly StockService $stockService,
        private ?StockWarehouseResolver $warehouses = null,
    ) {}

    private function warehouses(): StockWarehouseResolver
    {
        return $this->warehouses ??= app(StockWarehouseResolver::class);
    }

    /**
     * Convert order items to stock items for stock reduction
     * Manufactured items are broken down into their components
     */
    public function convertOrderItemsToStockItems(Order $order): array
    {
        return $this->optimizeStockItems($this->stockItemContributions($order));
    }

    private function stockItemContributions(Order $order): array
    {
        $stockItems = [];

        /** @var OrderItem $orderItem */
        foreach ($order->items as $orderItem) {
            /** @var Product $product */
            $product = $orderItem->product;
            $warehouseId = $this->warehouses()->forOrder($product, $order);

            switch ($product->type) {
                case ProductType::Manufactured:
                    // Break down manufactured product into its components
                    $componentItems = $this->getManufacturedProductComponents($product, $orderItem->quantity, $warehouseId);
                    foreach ($componentItems as $componentItem) {
                        $stockItems[] = $componentItem + ['order_item_id' => $orderItem->id];
                    }
                    break;

                case ProductType::Consumable:
                    // Add consumable item directly
                    $stockItems[] = [
                        'order_item_id' => $orderItem->id,
                        'product_id' => $product->id,
                        'quantity' => $orderItem->quantity,
                        'warehouse_id' => $warehouseId,
                    ];
                    break;

                case ProductType::RawMaterial:
                    // Raw materials can't be sold directly in orders
                    Log::warning('Raw material found in order items', [
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                    ]);
                    break;
            }
        }

        return $stockItems;
    }

    /**
     * Get components of a manufactured product with their required quantities
     *
     * @param  array<int, int>  $visited
     */
    private function getManufacturedProductComponents(Product $manufacturedProduct, float $orderQuantity, int $warehouseId, array $visited = []): array
    {
        if (in_array($manufacturedProduct->id, $visited, true)) {
            Log::warning("Cyclic component dependency detected for manufactured product {$manufacturedProduct->id}");

            return [];
        }

        $visited[] = $manufacturedProduct->id;
        $components = [];

        // Get all components for this manufactured product
        $productComponents = ProductComponent::where('product_id', $manufacturedProduct->id)
            ->with('component')
            ->get();

        foreach ($productComponents as $productComponent) {
            $component = $productComponent->component;
            if (! $component instanceof Product) {
                continue;
            }

            $requiredQuantity = $productComponent->quantity * $orderQuantity;

            // Only include raw materials and consumables (not other manufactured products)
            if (in_array($component->type, [ProductType::RawMaterial, ProductType::Consumable])) {
                $components[] = [
                    'product_id' => $component->id,
                    'quantity' => $requiredQuantity,
                    'warehouse_id' => $warehouseId,
                ];
            } elseif ($component->type === ProductType::Manufactured) {
                // Nested components stay on the warehouse of the item that was sold
                $nestedComponents = $this->getManufacturedProductComponents($component, $requiredQuantity, $warehouseId, $visited);
                $components = array_merge($components, $nestedComponents);
            }
        }

        return $components;
    }

    /**
     * Optimize stock items by summing up quantities for the same product
     */
    private function optimizeStockItems(array $stockItems): array
    {
        $optimized = [];

        foreach ($stockItems as $item) {
            unset($item['order_item_id']);
            $key = $item['product_id'].':'.($item['warehouse_id'] ?? 0);

            if (isset($optimized[$key])) {
                $optimized[$key]['quantity'] += $item['quantity'];
            } else {
                $optimized[$key] = $item;
            }
        }

        return array_values($optimized);
    }

    /**
     * Remove stock items when order is completed
     */
    public function removeStockForCompletedOrder(Order $order): bool
    {
        $stockItems = $this->convertOrderItemsToStockItems($order);

        if (empty($stockItems)) {
            return true;
        }

        return $this->stockService->removeStock(
            $stockItems,
            MovementReason::ORDER,
            $order
        )->isNotEmpty();
    }

    /** @return Collection<int, InventoryItemMovement> */
    public function removeStockWithRealCostsForCompletedOrder(Order $order): Collection
    {
        $contributions = collect($this->stockItemContributions($order));
        if ($contributions->isEmpty()) {
            return collect();
        }

        $movements = $this->stockService->removeStock(
            $this->optimizeStockItems($contributions->all()), MovementReason::ORDER, $order
        );
        $byStockLine = $contributions->groupBy(fn (array $line): string => $line['product_id'].':'.$line['warehouse_id']);
        $costs = [];
        foreach ($movements as $movement) {
            $lines = $byStockLine->get($movement->product_id.':'.$movement->warehouse_id, collect());
            $quantity = (float) $lines->sum('quantity');
            if ($quantity <= 0) {
                continue;
            }
            foreach ($lines as $line) {
                $id = $line['order_item_id'];
                $costs[$id] = ($costs[$id] ?? 0) + (float) $movement->total_cost * $line['quantity'] / $quantity;
            }
        }

        /** @var OrderItem $item */
        foreach ($order->items as $item) {
            if (array_key_exists($item->id, $costs) && $item->quantity > 0) {
                $item->update(['cost' => round($costs[$item->id] / $item->quantity, 4)]);
            }
        }

        return $movements;
    }

    /**
     * Add stock items back when order is cancelled after completion
     */
    public function addStockForCancelledOrder(Order $order): bool
    {
        $stockItems = $order->exists
            ? $this->fullCancellationStockItems($order)
            : $this->partialReturnStockItems($order);

        if ($stockItems === []) {
            return true;
        }

        return $this->stockService->addStock(
            $stockItems,
            MovementReason::ORDER_RETURN,
            $order
        )->isNotEmpty();
    }

    /**
     * @return array<int, array{product_id: int, quantity: float, warehouse_id: int}>
     */
    private function fullCancellationStockItems(Order $order): array
    {
        $movements = $this->originalSaleMovements($order);

        if ($movements->isEmpty()) {
            return $this->convertOrderItemsToStockItems($order);
        }

        $returnedByProductWarehouse = InventoryItemMovement::query()
            ->where('referenceable_type', $order->getMorphClass())
            ->where('referenceable_id', $order->getKey())
            ->where('reason', MovementReason::ORDER_RETURN)
            ->orderBy('id')
            ->get()
            ->groupBy(fn (InventoryItemMovement $movement): string => $movement->product_id.':'.$movement->warehouse_id)
            ->map(fn (Collection $group): float => (float) $group->sum('quantity'))
            ->all();

        $outstandingItems = [];

        foreach ($movements as $movement) {
            $key = $movement->product_id.':'.$movement->warehouse_id;
            $alreadyReturned = $returnedByProductWarehouse[$key] ?? 0.0;
            $originalQuantity = (float) $movement->quantity;
            $deducted = min($originalQuantity, $alreadyReturned);
            $returnedByProductWarehouse[$key] = max(0.0, $alreadyReturned - $deducted);
            $outstanding = round($originalQuantity - $deducted, 3);

            if ($outstanding > 0) {
                $outstandingItems[] = [
                    'product_id' => (int) $movement->product_id,
                    'quantity' => $outstanding,
                    'warehouse_id' => (int) $movement->warehouse_id,
                ];
            }
        }

        return $outstandingItems;
    }

    /**
     * @return array<int, array{product_id: int, quantity: float, warehouse_id: int}>
     */
    private function partialReturnStockItems(Order $order): array
    {
        $lines = $this->convertOrderItemsToStockItems($order);
        $warehouses = $this->originalSaleMovements($order)
            ->groupBy(fn (InventoryItemMovement $movement): int => (int) $movement->product_id)
            ->map(fn (Collection $group): int => (int) $group->sortBy('id')->first()->warehouse_id);

        foreach ($lines as $index => $line) {
            $productId = (int) $line['product_id'];
            if ($warehouses->has($productId)) {
                $lines[$index]['warehouse_id'] = $warehouses->get($productId);
            }
        }

        return $lines;
    }

    /**
     * @return Collection<int, InventoryItemMovement>
     */
    private function originalSaleMovements(Order $order): Collection
    {
        if ($order->getKey() === null) {
            return collect();
        }

        return InventoryItemMovement::query()
            ->where('referenceable_type', $order->getMorphClass())
            ->where('referenceable_id', $order->getKey())
            ->where('reason', MovementReason::ORDER)
            ->orderBy('id')
            ->get();
    }
}
