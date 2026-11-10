<?php

namespace App\Costing\Strategies;

use App\Costing\CostHistoryRecorder;
use App\Costing\CostingMethod;
use App\Enums\MovementReason;
use App\Models\InventoryItem;
use App\Models\InventoryItemMovement;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class WeightedAverageCostingStrategy extends OffCostingStrategy
{
    public function method(): CostingMethod
    {
        return CostingMethod::WeightedAverage;
    }

    public function receive(InventoryItemMovement $in): void
    {
        DB::transaction(function () use ($in) {
            $product = Product::lockForUpdate()->findOrFail($in->product_id);
            $in->setRelation('product', $product);

            if (! in_array($in->reason, [MovementReason::PURCHASE, MovementReason::MANUFACTURING_IN], true)) {
                $this->valueMovement($in, $this->currentUnitCost($product));

                return;
            }

            parent::receive($in);
            $this->reaverage($product, $in);
        });
    }

    public function reverse(InventoryItemMovement $original, InventoryItemMovement $in): void
    {
        DB::transaction(function () use ($original, $in) {
            $product = Product::lockForUpdate()->findOrFail($in->product_id);
            $in->setRelation('product', $product);
            parent::reverse($original, $in);
            $this->reaverage($product, $in);
        });
    }

    private function reaverage(Product $product, InventoryItemMovement $in): void
    {
        $oldQuantity = 0.0;
        foreach (InventoryItem::where('product_id', $product->id)->orderBy('warehouse_id')->lockForUpdate()->get() as $item) {
            $oldQuantity += (float) $item->quantity;
        }
        $oldQuantity = max(0, $oldQuantity);
        $quantity = (float) $in->quantity;
        $incomingCost = $in->total_cost !== null ? (float) $in->total_cost : ($quantity * (float) $in->unit_cost);
        $newCost = round(($oldQuantity * (float) $product->cost + $incomingCost) / ($oldQuantity + $quantity), 4);

        app(CostHistoryRecorder::class)->during($this->method(), $in->referenceable, function () use ($product, $newCost) {
            $product->cost = $newCost;
            $product->save();
        });
    }
}
