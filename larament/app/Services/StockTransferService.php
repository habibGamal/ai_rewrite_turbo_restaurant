<?php

namespace App\Services;

use App\Enums\MovementReason;
use App\Enums\ProductType;
use App\Models\StockTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockTransferService
{
    public function __construct(
        private readonly StockService $stockService,
        private readonly InventoryDailyAggregationService $dailyAggregationService,
    ) {}

    public function confirm(StockTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer): void {
            $locked = StockTransfer::whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->closed_at !== null) {
                throw ValidationException::withMessages(['stock_transfer' => 'إذن التحويل مؤكد بالفعل.']);
            }
            if ($locked->from_warehouse_id === $locked->to_warehouse_id) {
                throw ValidationException::withMessages(['to_warehouse_id' => 'يجب أن يختلف المخزن المصدر عن المخزن المستلم.']);
            }
            shouldDayBeOpen();
            $items = $locked->items()->with('product')->orderBy('id')->lockForUpdate()->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'يجب إضافة صنف واحد على الأقل للتحويل.']);
            }
            foreach ($items as $item) {
                if ((float) $item->quantity <= 0 || ! $item->product || ! in_array($item->product->type, [ProductType::RawMaterial, ProductType::Consumable], true)) {
                    throw ValidationException::withMessages(['items' => 'التحويل يقبل المواد الخام والمنتجات الاستهلاكية فقط بكميات أكبر من صفر.']);
                }
            }

            $movements = $this->stockService->transfer(
                $locked->from_warehouse_id, $locked->to_warehouse_id,
                $items->map(fn ($item): array => ['product_id' => $item->product_id, 'quantity' => (float) $item->quantity])->all(),
                $locked,
            )->where('reason', MovementReason::TRANSFER_OUT)->keyBy('product_id');

            foreach ($items->groupBy('product_id') as $productId => $lines) {
                $movement = $movements->get($productId);
                $allocated = 0.0;
                foreach ($lines->values() as $index => $item) {
                    $cost = $index === $lines->count() - 1
                        ? round((float) $movement->total_cost - $allocated, 2)
                        : round((float) $movement->total_cost * (float) $item->quantity / (float) $movement->quantity, 2);
                    $item->update(['unit_cost' => $movement->unit_cost, 'total_cost' => $cost]);
                    $allocated += $cost;
                }
            }
            $locked->update([
                'total_cost' => round((float) $movements->sum('total_cost'), 2),
                'closed_at' => now(),
                'closed_by' => auth()->id(),
                'business_date' => $this->dailyAggregationService->currentBusinessDate()->toDateString(),
            ]);
        });
        $transfer->refresh();
    }
}
