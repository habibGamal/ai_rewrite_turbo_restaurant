<?php

namespace App\Services;

use App\Enums\MovementReason;
use App\Enums\ProductType;
use App\Models\Product;
use App\Models\StockIssue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockIssueService
{
    public function __construct(
        private StockService $stockService,
        private InventoryDailyAggregationService $dailyAggregationService,
    ) {}

    /**
     * Validate raw item arrays for a stock issue form.
     *
     * @param  array<int|string, array<string, mixed>>  $items
     */
    public function validateItems(array $items): void
    {
        $items = array_values($items);

        if (count($items) === 0) {
            throw ValidationException::withMessages([
                'items' => 'يجب إضافة صنف واحد على الأقل لإذن الصرف.',
            ]);
        }

        $productIds = [];
        foreach ($items as $item) {
            $productId = isset($item['product_id']) ? (int) $item['product_id'] : 0;
            $quantity = isset($item['quantity']) ? (float) $item['quantity'] : 0.0;

            if ($productId <= 0) {
                throw ValidationException::withMessages([
                    'items' => 'يجب اختيار منتج صالح لكل صنف.',
                ]);
            }

            if ($quantity <= 0) {
                throw ValidationException::withMessages([
                    'items' => 'يجب أن تكون الكمية أكبر من صفر.',
                ]);
            }

            $productIds[] = $productId;
        }

        $products = Product::query()
            ->whereIn('id', array_unique($productIds))
            ->get()
            ->keyBy('id');

        foreach ($productIds as $id) {
            $product = $products->get($id);
            if (! $product || ! in_array($product->type, [ProductType::RawMaterial, ProductType::Consumable], true)) {
                throw ValidationException::withMessages([
                    'items' => 'أذون الصرف تقبل المواد الخام والمنتجات الاستهلاكية فقط.',
                ]);
            }
        }
    }

    public function confirm(StockIssue $issue): void
    {
        if ($issue->closed_at !== null) {
            throw ValidationException::withMessages([
                'stock_issue' => 'إذن الصرف مؤكد بالفعل.',
            ]);
        }

        shouldDayBeOpen();

        DB::transaction(function () use ($issue) {
            $locked = StockIssue::whereKey($issue->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->closed_at !== null) {
                throw ValidationException::withMessages([
                    'stock_issue' => 'إذن الصرف مؤكد بالفعل.',
                ]);
            }

            $items = $locked->items()->with('product')->orderBy('id')->lockForUpdate()->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'يجب إضافة صنف واحد على الأقل لإذن الصرف.',
                ]);
            }

            $itemsToRemove = [];
            foreach ($items as $item) {
                if ((float) $item->quantity <= 0) {
                    throw ValidationException::withMessages([
                        'items' => 'يجب أن تكون الكمية أكبر من صفر.',
                    ]);
                }

                if (! $item->product || ! in_array($item->product->type, [ProductType::RawMaterial, ProductType::Consumable], true)) {
                    throw ValidationException::withMessages([
                        'items' => 'أذون الصرف تقبل المواد الخام والمنتجات الاستهلاكية فقط.',
                    ]);
                }

                $itemsToRemove[] = [
                    'product_id' => (int) $item->product_id,
                    'quantity' => (float) $item->quantity,
                    'warehouse_id' => (int) $locked->warehouse_id,
                ];
            }

            $movements = $this->stockService->removeStock(
                $itemsToRemove,
                MovementReason::STOCK_ISSUE,
                $locked
            )->keyBy('product_id');

            $totalCost = 0.0;
            $itemsByProduct = $items->groupBy('product_id');

            foreach ($itemsByProduct as $productId => $productItems) {
                $movement = $movements->get((int) $productId);
                $unitCost = $movement !== null ? (float) $movement->unit_cost : 0.0;
                $movementTotalCost = $movement !== null ? (float) $movement->total_cost : 0.0;

                if ($productItems->count() === 1) {
                    $item = $productItems->first();
                    $item->update([
                        'unit_cost' => $unitCost,
                        'total_cost' => $movementTotalCost,
                    ]);
                    $totalCost += $movementTotalCost;
                } else {
                    $allocated = 0.0;
                    $lastIndex = $productItems->count() - 1;
                    foreach ($productItems->values() as $idx => $item) {
                        $lineTotal = ($idx === $lastIndex)
                            ? round($movementTotalCost - $allocated, 2)
                            : round((float) $item->quantity * $unitCost, 2);
                        $allocated += $lineTotal;
                        $item->update([
                            'unit_cost' => $unitCost,
                            'total_cost' => $lineTotal,
                        ]);
                        $totalCost += $lineTotal;
                    }
                }
            }

            $locked->update([
                'total_cost' => round($totalCost, 2),
                'closed_at' => now(),
                'closed_by' => auth()->id(),
                'business_date' => $this->dailyAggregationService->currentBusinessDate()->toDateString(),
            ]);
        });

        $issue->refresh();
    }
}
