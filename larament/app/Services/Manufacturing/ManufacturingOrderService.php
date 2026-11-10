<?php

namespace App\Services\Manufacturing;

use App\Enums\MovementReason;
use App\Exceptions\ManufacturingException;
use App\Models\ManufacturingOrder;
use App\Models\ManufacturingRecipe;
use App\Models\Warehouse;
use App\Services\InventoryDailyAggregationService;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManufacturingOrderService
{
    public function __construct(
        private readonly StockService $stockService,
        private readonly InventoryDailyAggregationService $dailyAggregationService,
    ) {}

    public function createFromRecipe(ManufacturingRecipe $r, int $sourceWarehouseId, int $destinationWarehouseId, float $plannedMainQuantity): ManufacturingOrder
    {
        return DB::transaction(function () use ($r, $sourceWarehouseId, $destinationWarehouseId, $plannedMainQuantity) {
            $recipe = ManufacturingRecipe::query()->lockForUpdate()->findOrFail($r->id);
            $recipe->validate();
            if (! $recipe->is_active || ! is_finite($plannedMainQuantity) || round($plannedMainQuantity, 3) <= 0) {
                throw ValidationException::withMessages(['planned_main_quantity' => 'اختر وصفة نشطة وكمية مخططة أكبر من صفر']);
            }
            foreach (['source_warehouse_id' => $sourceWarehouseId, 'destination_warehouse_id' => $destinationWarehouseId] as $field => $id) {
                if (! Warehouse::active()->whereKey($id)->exists()) {
                    throw ValidationException::withMessages([$field => 'يجب اختيار مخزن نشط']);
                }
            }

            $scale = round($plannedMainQuantity, 3) / (float) $recipe->mainOutput->expected_quantity;
            $order = ManufacturingOrder::create([
                'manufacturing_recipe_id' => $recipe->id,
                'source_warehouse_id' => $sourceWarehouseId,
                'destination_warehouse_id' => $destinationWarehouseId,
                'planned_main_quantity' => round($plannedMainQuantity, 3),
                'user_id' => auth()->id(),
            ]);
            foreach ($recipe->inputs as $input) {
                $quantity = round((float) $input->quantity * $scale, 3);
                $order->inputs()->create([
                    'product_id' => $input->product_id, 'planned_quantity' => $quantity, 'actual_quantity' => $quantity,
                ]);
            }
            foreach ($recipe->outputs as $output) {
                $order->outputs()->create([
                    'product_id' => $output->product_id, 'is_main' => $output->is_main,
                    'cost_method' => $output->cost_method ?? 'percent', 'cost_value' => $output->cost_value ?? 0,
                    'expected_quantity' => round((float) $output->expected_quantity * $scale, 3),
                ]);
            }

            return $order->load(['inputs', 'outputs']);
        });
    }

    /** @param array<int, float|int|string> $actualOutputQuantities */
    public function complete(ManufacturingOrder $o, array $actualOutputQuantities): void
    {
        DB::transaction(function () use ($o, $actualOutputQuantities): void {
            $order = ManufacturingOrder::query()->lockForUpdate()->findOrFail($o->id);
            $order->assertDraft();
            $order->setRelation('inputs', $order->inputs()->orderBy('id')->lockForUpdate()->get());
            $order->setRelation('outputs', $order->outputs()->orderBy('id')->lockForUpdate()->get());
            $quantities = $this->validateActualOutputs($order, $actualOutputQuantities);
            $inputs = $order->inputs->filter(fn ($input) => (float) $input->actual_quantity > 0);
            app(ManufacturingRecipeService::class)->validateData(
                $inputs->map(fn ($input) => ['product_id' => $input->product_id, 'quantity' => (float) $input->actual_quantity])->all(),
                $order->outputs->toArray(),
            );

            $movements = $this->stockService->removeStock($inputs->map(fn ($input) => [
                'product_id' => $input->product_id,
                'quantity' => (float) $input->actual_quantity,
                'warehouse_id' => $order->source_warehouse_id,
            ])->values()->all(), MovementReason::MANUFACTURING_OUT, $order);
            $pot = round((float) $movements->sum('total_cost'), 2);
            $byProduct = $movements->keyBy('product_id');
            foreach ($order->inputs->groupBy('product_id') as $productId => $lines) {
                $movement = $byProduct->get($productId);
                $totalCost = (float) ($movement->total_cost ?? 0);
                $totalQuantity = (float) $lines->sum('actual_quantity');
                $cumulativeQuantity = 0.0;
                $allocated = 0.0;
                foreach ($lines as $line) {
                    $cumulativeQuantity += (float) $line->actual_quantity;
                    $cumulativeCost = $totalQuantity > 0 ? round($totalCost * $cumulativeQuantity / $totalQuantity, 2) : 0.0;
                    $line->update(['unit_cost' => $movement->unit_cost ?? 0, 'total_cost' => round($cumulativeCost - $allocated, 2)]);
                    $allocated = $cumulativeCost;
                }
            }

            $allocations = OutputCostAllocationService::allocate($pot, $order->outputs->map(fn ($output) => [
                'key' => $output->id, 'is_main' => $output->is_main,
                'cost_method' => $output->cost_method, 'cost_value' => (float) $output->cost_value,
                'actual_quantity' => $quantities[$output->id],
            ])->all());
            foreach ($order->outputs as $output) {
                $quantity = $quantities[$output->id];
                $allocation = $allocations[$output->id];
                if ($quantity > 0) {
                    $this->stockService->addStock([[
                        'product_id' => $output->product_id, 'quantity' => $quantity,
                        'warehouse_id' => $order->destination_warehouse_id,
                        'unit_cost' => $allocation['unit_cost'],
                        'total_cost' => $allocation['allocated_cost'],
                    ]], MovementReason::MANUFACTURING_IN, $order);
                }
                $variance = round($quantity - (float) $output->expected_quantity, 3);
                $output->update([
                    ...$allocation, 'actual_quantity' => $quantity, 'variance_quantity' => $variance,
                    'variance_value' => round($variance * $allocation['unit_cost'], 2, PHP_ROUND_HALF_UP),
                ]);
            }
            $order->update([
                'total_input_cost' => $pot, 'closed_at' => now(), 'closed_by' => auth()->id(),
                'business_date' => $this->dailyAggregationService->currentBusinessDate(),
            ]);
        });
        $o->refresh();
    }

    /**
     * @param  array<int, float|int|string>  $actualOutputQuantities
     * @return array<int, float>
     */
    private function validateActualOutputs(ManufacturingOrder $order, array $actualOutputQuantities): array
    {
        $ids = $order->outputs->modelKeys();
        if (array_diff($ids, array_keys($actualOutputQuantities)) || array_diff(array_keys($actualOutputQuantities), $ids)) {
            throw new ManufacturingException('يجب إدخال الكمية الفعلية لكل مخرج من مخرجات الأمر فقط');
        }
        $quantities = [];
        foreach ($order->outputs as $output) {
            $value = $actualOutputQuantities[$output->id];
            if (! is_numeric($value) || ! is_finite((float) $value)) {
                throw new ManufacturingException('يجب إدخال كمية فعلية صالحة لكل مخرج');
            }
            $quantity = round((float) $value, 3);
            if ($output->is_main && $quantity <= 0) {
                throw new ManufacturingException('يجب أن تكون الكمية الفعلية للمخرج الرئيسي أكبر من صفر');
            }
            if ((float) $value < 0) {
                throw new ManufacturingException('لا يمكن أن تكون الكمية الفعلية للمخرجات سالبة');
            }
            $quantities[$output->id] = $quantity;
        }

        return $quantities;
    }
}
