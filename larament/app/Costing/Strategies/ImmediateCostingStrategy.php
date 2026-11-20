<?php

namespace App\Costing\Strategies;

use App\Costing\CostHistoryRecorder;
use App\Costing\CostingMethod;
use App\Enums\MovementReason;
use App\Models\InventoryItemMovement;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class ImmediateCostingStrategy extends OffCostingStrategy
{
    public function method(): CostingMethod
    {
        return CostingMethod::Immediate;
    }

    public function receive(InventoryItemMovement $in): void
    {
        DB::transaction(function () use ($in) {
            $product = Product::lockForUpdate()->findOrFail($in->product_id);
            $in->setRelation('product', $product);
            parent::receive($in);

            if (in_array($in->reason, [MovementReason::PURCHASE, MovementReason::MANUFACTURING_IN], true)) {
                app(CostHistoryRecorder::class)->during($this->method(), $in->referenceable, function () use ($product, $in) {
                    $product->cost = (float) $in->unit_cost;
                    $product->save();
                });
            }
        });
    }
}
