<?php

namespace App\Services;

use App\Enums\MovementReason;
use App\Models\Waste;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WasteService
{
    private StockService $stockService;

    public function __construct(StockService $stockService)
    {
        $this->stockService = $stockService;
    }

    /**
     * Close the waste record and remove stock from inventory
     */
    public function closeWaste(Waste $waste): void
    {
        if ($waste->closed_at) {
            throw new Exception('سجل التالف مغلق بالفعل');
        }

        shouldDayBeOpen();

        DB::beginTransaction();

        try {
            $locked = Waste::whereKey($waste->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->closed_at) {
                throw new Exception('سجل التالف مغلق بالفعل');
            }

            // Load waste items with products
            $items = $locked->items()->with('product')->orderBy('id')->lockForUpdate()->get();

            // Prepare items for stock removal
            $itemsToRemove = [];
            foreach ($items as $item) {
                $itemsToRemove[] = [
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'warehouse_id' => $locked->warehouse_id,
                ];
            }

            // Remove stock for all wasted items
            if (! empty($itemsToRemove)) {
                $this->stockService->removeStock($itemsToRemove, MovementReason::WASTE, $locked);
                Log::info('Removed stock for '.count($itemsToRemove)." products in waste {$locked->id}");
            }

            // Mark waste as closed
            $locked->update([
                'closed_at' => now(),
                'business_date' => app(InventoryDailyAggregationService::class)->currentBusinessDate()->toDateString(),
                'total' => $items->sum('total'),
            ]);

            DB::commit();

            $waste->refresh();

            Log::info("Waste {$waste->id} closed successfully with ".count($itemsToRemove).' items removed from inventory');

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Failed to close waste {$waste->id}: ".$e->getMessage());
            throw new Exception('فشل في إغلاق سجل التالف: '.$e->getMessage());
        }
    }
}
