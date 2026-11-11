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

    /**
     * Create an order from a recipe and persist the initial form state lines.
     *
     * @param  array<int|string, array<string, mixed>>  $inputs
     * @param  array<int|string, array<string, mixed>>  $outputs
     */
    public function createOrderWithLines(
        ManufacturingRecipe $recipe,
        int $sourceWarehouseId,
        int $destinationWarehouseId,
        float $plannedMainQuantity,
        array $inputs,
        array $outputs,
        ?string $notes = null,
    ): ManufacturingOrder {
        return DB::transaction(function () use ($recipe, $sourceWarehouseId, $destinationWarehouseId, $plannedMainQuantity, $inputs, $outputs, $notes) {
            $order = $this->createFromRecipe($recipe, $sourceWarehouseId, $destinationWarehouseId, $plannedMainQuantity);
            if ($notes !== null) {
                $order->update(['notes' => $notes]);
            }

            return $this->saveDraftLines($order, $inputs, $outputs, $plannedMainQuantity, $notes);
        });
    }

    /**
     * Save draft order lines (actual quantities, extra inputs, extra outputs).
     *
     * @param  array<int|string, array<string, mixed>>  $inputs
     * @param  array<int|string, array<string, mixed>>  $outputs
     */
    public function saveDraftLines(
        ManufacturingOrder $order,
        array $inputs,
        array $outputs,
        ?float $plannedMainQuantity = null,
        ?string $notes = null,
    ): ManufacturingOrder {
        return DB::transaction(function () use ($order, $inputs, $outputs, $plannedMainQuantity, $notes) {
            $order = ManufacturingOrder::query()->lockForUpdate()->findOrFail($order->id);
            $order->assertDraft();

            if ($notes !== null && $order->notes !== $notes) {
                $order->notes = $notes;
                $order->save();
            }

            if ($plannedMainQuantity !== null && (float) $plannedMainQuantity > 0 && (float) $plannedMainQuantity != (float) $order->planned_main_quantity) {
                ManufacturingOrder::query()->whereKey($order->id)->update([
                    'planned_main_quantity' => round((float) $plannedMainQuantity, 3),
                ]);
                $order->planned_main_quantity = round((float) $plannedMainQuantity, 3);
            }

            // Sync inputs
            $existingInputs = $order->inputs()->get()->keyBy('id');
            $keptInputIds = [];

            foreach ($inputs as $inputData) {
                $productId = (int) ($inputData['product_id'] ?? 0);
                if ($productId <= 0) {
                    continue;
                }

                $actualQty = round((float) ($inputData['actual_quantity'] ?? 0), 3);
                $plannedQty = round((float) ($inputData['planned_quantity'] ?? 0), 3);
                $inputId = ! empty($inputData['id']) ? (int) $inputData['id'] : null;

                if ($inputId && $existingInputs->has($inputId)) {
                    $line = $existingInputs->get($inputId);
                    $newPlanned = $plannedQty > 0 ? $plannedQty : (float) $line->planned_quantity;
                    \App\Models\ManufacturingOrderInput::query()->whereKey($inputId)->update([
                        'planned_quantity' => $newPlanned,
                        'actual_quantity' => $actualQty,
                    ]);
                    $keptInputIds[] = $inputId;
                } else {
                    $matched = $existingInputs->first(fn ($item) => $item->product_id === $productId && ! in_array($item->id, $keptInputIds, true));
                    if ($matched) {
                        $newPlanned = $plannedQty > 0 ? $plannedQty : (float) $matched->planned_quantity;
                        \App\Models\ManufacturingOrderInput::query()->whereKey($matched->id)->update([
                            'planned_quantity' => $newPlanned,
                            'actual_quantity' => $actualQty,
                        ]);
                        $keptInputIds[] = $matched->id;
                    } else {
                        $created = $order->inputs()->create([
                            'product_id' => $productId,
                            'planned_quantity' => $plannedQty,
                            'actual_quantity' => $actualQty,
                        ]);
                        $keptInputIds[] = $created->id;
                    }
                }
            }

            foreach ($existingInputs as $existingInput) {
                if (! in_array($existingInput->id, $keptInputIds, true)) {
                    $existingInput->delete();
                }
            }

            // Sync outputs
            $existingOutputs = $order->outputs()->get()->keyBy('id');
            $keptOutputIds = [];

            foreach ($outputs as $outputData) {
                $productId = (int) ($outputData['product_id'] ?? 0);
                if ($productId <= 0) {
                    continue;
                }

                $isMain = (bool) ($outputData['is_main'] ?? false);
                $actualQty = round((float) ($outputData['actual_quantity'] ?? 0), 3);
                $expectedQty = round((float) ($outputData['expected_quantity'] ?? 0), 3);
                $costMethod = (string) ($outputData['cost_method'] ?? 'percent');
                $costValue = (float) ($outputData['cost_value'] ?? 0.0);
                $outputId = ! empty($outputData['id']) ? (int) $outputData['id'] : null;

                if ($outputId && $existingOutputs->has($outputId)) {
                    $line = $existingOutputs->get($outputId);
                    $newExpected = $expectedQty > 0 ? $expectedQty : (float) $line->expected_quantity;
                    \App\Models\ManufacturingOrderOutput::query()->whereKey($line->id)->update([
                        'expected_quantity' => $newExpected,
                        'actual_quantity' => $actualQty,
                        'cost_method' => $line->is_main ? 'percent' : $costMethod,
                        'cost_value' => $line->is_main ? 0.0 : $costValue,
                    ]);
                    $keptOutputIds[] = $outputId;
                } else {
                    $matched = $existingOutputs->first(fn ($item) => $item->product_id === $productId && $item->is_main === $isMain && ! in_array($item->id, $keptOutputIds, true));
                    if ($matched) {
                        $newExpected = $expectedQty > 0 ? $expectedQty : (float) $matched->expected_quantity;
                        \App\Models\ManufacturingOrderOutput::query()->whereKey($matched->id)->update([
                            'expected_quantity' => $newExpected,
                            'actual_quantity' => $actualQty,
                            'cost_method' => $matched->is_main ? 'percent' : $costMethod,
                            'cost_value' => $matched->is_main ? 0.0 : $costValue,
                        ]);
                        $keptOutputIds[] = $matched->id;
                    } else {
                        $created = $order->outputs()->create([
                            'product_id' => $productId,
                            'is_main' => $isMain,
                            'expected_quantity' => $expectedQty,
                            'actual_quantity' => $actualQty,
                            'cost_method' => $costMethod,
                            'cost_value' => $costValue,
                        ]);
                        $keptOutputIds[] = $created->id;
                    }
                }
            }

            foreach ($existingOutputs as $existingOutput) {
                if (! in_array($existingOutput->id, $keptOutputIds, true)) {
                    if (! $existingOutput->is_main && (float) $existingOutput->expected_quantity == 0) {
                        $existingOutput->delete();
                    }
                }
            }

            return $order->fresh(['inputs', 'outputs']);
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
                $order->outputs->map(fn ($output) => [
                    'product_id' => $output->product_id,
                    'is_main' => (bool) $output->is_main,
                    'expected_quantity' => (float) ($output->expected_quantity > 0 ? $output->expected_quantity : ($quantities[$output->id] > 0 ? $quantities[$output->id] : 1)),
                    'cost_method' => $output->cost_method,
                    'cost_value' => (float) $output->cost_value,
                ])->all(),
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
