<?php

namespace App\Services;

use App\Enums\MovementReason;
use App\Models\InventoryItem;
use App\Models\Stocktaking;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StocktakingService
{
    private StockService $stockService;

    public function __construct(StockService $stockService)
    {
        $this->stockService = $stockService;
    }

    /**
     * Get items whose current stock differs from the snapshot taken when the item was counted.
     *
     * @return array<int, array{name: string, snapshot: float, current: float}>
     */
    public function getChangedItemsSinceCount(Stocktaking $stocktaking): array
    {
        $stocktaking->loadMissing(['items.product']);
        $warehouseId = (int) $stocktaking->warehouse_id;

        $changed = [];

        /** @var iterable<\App\Models\StocktakingItem> $items */
        /** @phpstan-ignore-next-line */
        $items = $stocktaking->items;

        foreach ($items as $item) {
            $snapshot = round((float) ($item->stock_quantity ?? 0), 3);
            $current = round((float) $this->stockService->getCurrentStock((int) $item->product_id, $warehouseId), 3);

            if (abs($snapshot - $current) >= 0.0005) {
                $changed[] = [
                    'name' => (string) ($item->product->name ?? 'غير معروف'),
                    'snapshot' => $snapshot,
                    'current' => $current,
                ];
            }
        }

        return $changed;
    }

    /**
     * Close the stocktaking and update inventory quantities using StockService
     */
    public function closeStocktaking(Stocktaking $stocktaking): void
    {
        if ($stocktaking->closed_at) {
            throw new Exception('الجرد مغلق بالفعل');
        }

        shouldDayBeOpen();

        DB::beginTransaction();

        try {
            $locked = Stocktaking::whereKey($stocktaking->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->closed_at) {
                throw new Exception('الجرد مغلق بالفعل');
            }

            // Load stocktaking items with products under lock
            $items = $locked->items()->with('product')->orderBy('id')->lockForUpdate()->get();

            // Group items by variance type for bulk operations
            $itemsToAdd = [];
            $itemsToRemove = [];
            $warehouseId = (int) $locked->warehouse_id;
            $productIds = $items->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

            if (! empty($productIds)) {
                InventoryItem::query()
                    ->where('warehouse_id', $warehouseId)
                    ->whereIn('product_id', $productIds)
                    ->orderBy('product_id')
                    ->lockForUpdate()
                    ->get();
            }

            foreach ($items as $item) {
                $currentStock = $this->stockService->getCurrentStock($item->product_id, $warehouseId);
                $realQuantity = $item->real_quantity;
                $variance = $realQuantity - $currentStock;

                if ($variance > 0) {
                    // Need to add stock
                    $itemsToAdd[] = [
                        'product_id' => $item->product_id,
                        'quantity' => $variance,
                        'warehouse_id' => $warehouseId,
                    ];
                } elseif ($variance < 0) {
                    // Need to remove stock
                    $itemsToRemove[] = [
                        'product_id' => $item->product_id,
                        'quantity' => abs($variance),
                        'warehouse_id' => $warehouseId,
                    ];
                }
            }

            // Perform bulk stock operations
            if (! empty($itemsToAdd)) {
                $this->stockService->addStock($itemsToAdd, MovementReason::STOCKTAKING, $locked);
                Log::info('Added stock for '.count($itemsToAdd)." products in stocktaking {$locked->id}");
            }

            if (! empty($itemsToRemove)) {
                $this->stockService->removeStock($itemsToRemove, MovementReason::STOCKTAKING, $locked);
                Log::info('Removed stock for '.count($itemsToRemove)." products in stocktaking {$locked->id}");
            }

            // Mark stocktaking as closed
            $locked->update([
                'closed_at' => now(),
                'business_date' => app(InventoryDailyAggregationService::class)->currentBusinessDate()->toDateString(),
                'total' => $items->sum('total'),
            ]);

            DB::commit();

            $stocktaking->refresh();

            Log::info("Stocktaking {$stocktaking->id} closed successfully with ".
                     (count($itemsToAdd) + count($itemsToRemove)).' inventory adjustments');

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Failed to close stocktaking {$stocktaking->id}: ".$e->getMessage());
            throw new Exception('فشل في إغلاق الجرد: '.$e->getMessage());
        }
    }
}
