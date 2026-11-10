<?php

namespace App\Services\Orders;

use App\Enums\MovementReason;
use App\Enums\ProductType;
use App\Models\InventoryItemMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Services\StockService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class OrderStockConversionService
{
    public function __construct(
        private readonly StockService $stockService
    ) {}

    /**
     * Convert order items to stock items for stock reduction
     * Manufactured items are broken down into their components
     */
    public function convertOrderItemsToStockItems(Order $order): array
    {
        $stockItems = [];

        foreach ($order->items as $orderItem) {
            $product = $orderItem->product;
            $warehouseId = $product->stockWarehouseId();

            switch ($product->type) {
                case ProductType::Manufactured:
                    // Break down manufactured product into its components
                    $componentItems = $this->getManufacturedProductComponents($product, $orderItem->quantity, $warehouseId);
                    $stockItems = array_merge($stockItems, $componentItems);
                    break;

                case ProductType::Consumable:
                    // Add consumable item directly
                    $stockItems[] = [
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

        // Optimize by summing up common components
        return $this->optimizeStockItems($stockItems);
    }

    /**
     * Get components of a manufactured product with their required quantities
     */
    private function getManufacturedProductComponents(Product $manufacturedProduct, float $orderQuantity, int $warehouseId): array
    {
        $components = [];

        // Get all components for this manufactured product
        $productComponents = ProductComponent::where('product_id', $manufacturedProduct->id)
            ->with('component')
            ->get();

        foreach ($productComponents as $productComponent) {
            $component = $productComponent->component;
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
                $nestedComponents = $this->getManufacturedProductComponents($component, $requiredQuantity, $warehouseId);
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

        return $movements->map(fn (InventoryItemMovement $movement): array => [
            'product_id' => (int) $movement->product_id,
            'quantity' => (float) $movement->quantity,
            'warehouse_id' => (int) $movement->warehouse_id,
        ])->all();
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
