<?php

namespace App\Costing\Strategies;

use App\Costing\ConsumedCost;
use App\Costing\CostingMethod;
use App\Costing\CostingStrategy;
use App\Models\InventoryItem;
use App\Models\InventoryItemMovement;
use App\Models\Product;

class OffCostingStrategy implements CostingStrategy
{
    public function method(): CostingMethod
    {
        return CostingMethod::Off;
    }

    public function receive(InventoryItemMovement $in): void
    {
        $unitCost = $in->unit_cost === null ? $this->currentUnitCost($in->product) : (float) $in->unit_cost;
        $totalCost = ($in->unit_cost !== null && $in->total_cost !== null) ? (float) $in->total_cost : null;
        $this->valueMovement($in, $unitCost, $totalCost);
    }

    public function consume(InventoryItemMovement $out): ConsumedCost
    {
        $this->valueMovement($out, $this->currentUnitCost($out->product));

        return new ConsumedCost((float) $out->quantity, (float) $out->total_cost);
    }

    public function reverse(InventoryItemMovement $original, InventoryItemMovement $in): void
    {
        $this->valueMovement($in, $original->unit_cost === null ? $this->currentUnitCost($in->product) : (float) $original->unit_cost);
    }

    public function currentUnitCost(Product $product): float
    {
        return (float) $product->cost;
    }

    public function stockValue(Product $product, ?int $warehouseId = null): float
    {
        $quantity = InventoryItem::where('product_id', $product->id)
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))->sum('quantity');

        return (float) $quantity * $this->currentUnitCost($product);
    }

    protected function valueMovement(InventoryItemMovement $movement, float $unitCost, ?float $totalCost = null): void
    {
        $movement->unit_cost = round($unitCost, 4);
        $movement->total_cost = $totalCost !== null
            ? round($totalCost, 2)
            : round((float) $movement->quantity * (float) $movement->unit_cost, 2);
        $movement->save();
    }
}
