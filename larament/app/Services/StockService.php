<?php

namespace App\Services;

use App\Models\Product;
use App\Models\InventoryItem;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\ReturnPurchaseInvoice;
use App\Models\ReturnPurchaseInvoiceItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockService
{
    /**
     * Add stock quantity for a product
     */
    public function addStock(int $productId, float $quantity, string $reason = 'stock_in'): bool
    {
        try {
            DB::beginTransaction();

            $product = Product::findOrFail($productId);
            
            // Get or create inventory item
            $inventoryItem = InventoryItem::firstOrCreate(
                ['product_id' => $productId],
                ['quantity' => 0]
            );
            
            $inventoryItem->increment('quantity', $quantity);

            // Log the stock movement (implement StockMovement model later if needed)
            Log::info("Stock added: Product ID {$productId}, Quantity: {$quantity}, Reason: {$reason}");

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to add stock: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove stock quantity from a product
     */
    public function removeStock(int $productId, float $quantity, string $reason = 'stock_out'): bool
    {
        try {
            DB::beginTransaction();

            $product = Product::findOrFail($productId);
            
            // Get inventory item
            $inventoryItem = InventoryItem::where('product_id', $productId)->first();
            
            if (!$inventoryItem) {
                throw new \Exception("No inventory record found for product ID {$productId}");
            }
            
            // Check if sufficient stock is available
            if ($inventoryItem->quantity < $quantity) {
                throw new \Exception("Insufficient stock. Available: {$inventoryItem->quantity}, Required: {$quantity}");
            }

            $inventoryItem->decrement('quantity', $quantity);

            // Log the stock movement
            Log::info("Stock removed: Product ID {$productId}, Quantity: {$quantity}, Reason: {$reason}");

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to remove stock: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Process stock for multiple items
     */
    public function processMultipleItems(array $items, string $operation = 'add', string $reason = 'bulk_operation'): bool
    {
        try {
            DB::beginTransaction();

            foreach ($items as $item) {
                $productId = $item['product_id'];
                $quantity = $item['quantity'];

                if ($operation === 'add') {
                    $this->addStock($productId, $quantity, $reason);
                } else {
                    $this->removeStock($productId, $quantity, $reason);
                }
            }

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to process multiple items: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get current stock for a product
     */
    public function getCurrentStock(int $productId): float
    {
        $inventoryItem = InventoryItem::where('product_id', $productId)->first();
        return $inventoryItem ? $inventoryItem->quantity : 0;
    }

    /**
     * Check if product has sufficient stock
     */
    public function hasSufficientStock(int $productId, float $requiredQuantity): bool
    {
        return $this->getCurrentStock($productId) >= $requiredQuantity;
    }

    /**
     * Validate stock availability for multiple items
     */
    public function validateStockAvailability(array $items): array
    {
        $insufficientItems = [];

        foreach ($items as $item) {
            $productId = $item['product_id'];
            $quantity = $item['quantity'];

            if (!$this->hasSufficientStock($productId, $quantity)) {
                $product = Product::find($productId);
                $insufficientItems[] = [
                    'product_id' => $productId,
                    'product_name' => $product->name ?? 'Unknown',
                    'required_quantity' => $quantity,
                    'available_quantity' => $this->getCurrentStock($productId)
                ];
            }
        }

        return $insufficientItems;
    }
}
