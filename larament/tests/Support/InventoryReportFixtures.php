<?php

use App\Enums\MovementReason;
use App\Models\InventoryItemMovement;
use App\Models\ManufacturingOrder;
use App\Models\ManufacturingRecipe;
use App\Models\Product;
use App\Models\ProductCostHistory;
use App\Models\PurchaseInvoice;
use App\Models\StockIssue;
use App\Models\Stocktaking;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\Waste;
use Illuminate\Database\Eloquent\Model;

function reportMovement(Model $document, Product $product, string $reason, float $quantity, float $value, string $operation = 'out', ?int $warehouseId = null): InventoryItemMovement
{
    return InventoryItemMovement::withoutEvents(fn () => InventoryItemMovement::create([
        'referenceable_type' => $document->getMorphClass(), 'referenceable_id' => $document->id,
        'product_id' => $product->id, 'warehouse_id' => $warehouseId ?? $document->warehouse_id,
        'reason' => $reason, 'operation' => $operation, 'quantity' => $quantity,
        'unit_cost' => $quantity ? $value / $quantity : 0, 'total_cost' => $value,
    ]));
}

function reportPurchase(Product $product, float $quantity, float $price, array $attributes = []): PurchaseInvoice
{
    $invoice = PurchaseInvoice::factory()->create(array_merge([
        'supplier_id' => Supplier::firstOrCreate(['name' => 'Report supplier'])->id,
        'business_date' => '2026-11-05', 'closed_at' => now(),
    ], $attributes));
    $invoice->items()->create(['product_id' => $product->id, 'quantity' => $quantity, 'price' => $price, 'total' => $quantity * $price]);
    reportMovement($invoice, $product, MovementReason::PURCHASE->value, $quantity, $quantity * $price, 'in');

    return $invoice;
}

function reportWaste(Product $product, float $quantity, float $value, array $attributes = []): Waste
{
    $waste = Waste::factory()->create(array_merge(['business_date' => '2026-11-06', 'closed_at' => now(), 'notes' => 'تلف'], $attributes));
    $waste->items()->create(['product_id' => $product->id, 'quantity' => $quantity, 'price' => 999, 'total' => 999]);
    reportMovement($waste, $product, 'waste', $quantity, $value);

    return $waste;
}

function reportCount(Product $product, float $expected, float $actual, float $value, array $attributes = []): Stocktaking
{
    $count = Stocktaking::factory()->create(array_merge(['business_date' => '2026-11-07', 'closed_at' => now()], $attributes));
    $count->items()->create(['product_id' => $product->id, 'stock_quantity' => $expected, 'real_quantity' => $actual, 'price' => 999, 'total' => 999]);
    if ($actual !== $expected) {
        reportMovement($count, $product, 'stocktaking', abs($actual - $expected), abs($value), $actual > $expected ? 'in' : 'out');
    }

    return $count;
}

function reportIssue(Product $product, float $value, array $attributes = []): StockIssue
{
    $issue = StockIssue::factory()->create(array_merge(['destination' => 'وجبات العاملين', 'business_date' => '2026-11-08'], $attributes));
    $issue->items()->create(['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => $value / 2, 'total_cost' => $value]);
    reportMovement($issue, $product, 'stock_issue', 2, $value);
    if (! array_key_exists('closed_at', $attributes)) {
        $issue->update(['closed_at' => now()]);
    }

    return $issue;
}

function reportManufacturing(Product $product, float $expected, float $actual, array $attributes = []): ManufacturingOrder
{
    $order = ManufacturingOrder::create(array_merge([
        'manufacturing_recipe_id' => ManufacturingRecipe::factory()->create()->id,
        'source_warehouse_id' => Warehouse::default()->id, 'destination_warehouse_id' => Warehouse::default()->id,
        'planned_main_quantity' => $expected, 'business_date' => '2026-11-09',
    ], $attributes));
    $order->outputs()->create([
        'product_id' => $product->id, 'is_main' => true, 'cost_method' => 'percent', 'cost_value' => 100,
        'expected_quantity' => $expected, 'actual_quantity' => $actual, 'unit_cost' => 10,
        'allocated_cost' => $actual * 10, 'variance_quantity' => $actual - $expected, 'variance_value' => ($actual - $expected) * 10,
    ]);
    if (! array_key_exists('closed_at', $attributes)) {
        $order->update(['closed_at' => now()]);
    }

    return $order;
}

function reportFixture(string $report, Product $product, array $attributes = []): Model
{
    if ($report === 'ManufacturingYieldReport') {
        $attributes['destination_warehouse_id'] = $attributes['warehouse_id'] ?? Warehouse::default()->id;
        unset($attributes['warehouse_id']);
    }

    $document = match ($report) {
        'CostHistoryReport' => reportPurchase($product, 10, 20, $attributes),
        'ManufacturingYieldReport' => reportManufacturing($product, 100, 98, $attributes),
    };

    if ($report === 'CostHistoryReport') {
        return ProductCostHistory::create([
            'product_id' => $product->id, 'old_cost' => 20, 'new_cost' => 25, 'method' => 'weighted_average',
            'source_type' => $document->getMorphClass(), 'source_id' => $document->id,
        ]);
    }

    return $document;
}
