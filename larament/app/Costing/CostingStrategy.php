<?php

namespace App\Costing;

use App\Models\InventoryItemMovement;
use App\Models\Product;

interface CostingStrategy
{
    public function method(): CostingMethod;

    /** Incoming movement already saved with quantity, warehouse, reason and unit_cost (may be null). */
    public function receive(InventoryItemMovement $in): void;

    public function consume(InventoryItemMovement $out): ConsumedCost;

    public function reverse(InventoryItemMovement $original, InventoryItemMovement $in): void;

    public function currentUnitCost(Product $product): float;

    public function stockValue(Product $product, ?int $warehouseId = null): float;
}
