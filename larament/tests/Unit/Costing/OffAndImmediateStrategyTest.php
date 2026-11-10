<?php

use App\Costing\CostingMethod;
use App\Costing\CostingStrategy;
use App\Costing\Strategies\ImmediateCostingStrategy;
use App\Costing\Strategies\OffCostingStrategy;
use App\Enums\MovementReason;
use App\Models\InventoryItem;
use App\Models\InventoryItemMovement;
use App\Models\Product;
use App\Models\ProductCostHistory;
use App\Models\PurchaseInvoice;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('off keeps cost 20 and records the real incoming price 30', function () {
    $product = Product::factory()->create(['cost' => 20]);
    $in = InventoryItemMovement::factory()->create(['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 30]);
    app(OffCostingStrategy::class)->receive($in);
    expect((float) $product->fresh()->cost)->toBe(20.0)
        ->and((float) $in->fresh()->unit_cost)->toBe(30.0)
        ->and((float) $in->fresh()->total_cost)->toBe(300.0)
        ->and(ProductCostHistory::count())->toBe(0);
});

it('immediate changes 20 to 30 once with the source invoice', function () {
    $product = Product::factory()->create(['cost' => 20]);
    $invoice = PurchaseInvoice::factory()->create();
    $in = InventoryItemMovement::factory()->create(['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 30]);
    $in->referenceable()->associate($invoice)->save();
    app(ImmediateCostingStrategy::class)->receive($in);
    app(ImmediateCostingStrategy::class)->receive($in);
    $history = ProductCostHistory::sole();
    expect((float) $product->fresh()->cost)->toBe(30.0)
        ->and((float) $history->old_cost)->toBe(20.0)
        ->and((float) $history->new_cost)->toBe(30.0)
        ->and($history->method)->toBe(CostingMethod::Immediate)
        ->and($history->source->is($invoice))->toBeTrue();
});

it('immediate only changes cost for purchase and manufacturing in', function (MovementReason $reason, float $expected) {
    $product = Product::factory()->create(['cost' => 20]);
    $in = InventoryItemMovement::factory()->create(['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 30, 'reason' => $reason]);
    app(ImmediateCostingStrategy::class)->receive($in);
    expect((float) $product->fresh()->cost)->toBe($expected);
})->with([
    [MovementReason::PURCHASE, 30.0],
    [MovementReason::MANUFACTURING_IN, 30.0],
    [MovementReason::ORDER_RETURN, 20.0],
    [MovementReason::STOCKTAKING, 20.0],
    [MovementReason::MANUAL, 20.0],
]);

it('consumes 3 at current cost 25 for a total of 75', function (string $strategy) {
    $product = Product::factory()->create(['cost' => 25]);
    $out = InventoryItemMovement::factory()->create(['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 99, 'operation' => 'out']);
    $cost = app($strategy)->consume($out);
    expect($cost->quantity)->toBe(3.0)->and($cost->totalCost)->toBe(75.0)->and($cost->unitCost())->toBe(25.0)
        ->and((float) $out->fresh()->total_cost)->toBe(75.0)->and((float) $out->fresh()->unit_cost)->toBe(25.0);
})->with([OffCostingStrategy::class, ImmediateCostingStrategy::class]);

it('fills an unspecified incoming cost from the current product cost', function () {
    $product = Product::factory()->create(['cost' => 25]);
    $in = InventoryItemMovement::factory()->create(['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => null]);
    app(OffCostingStrategy::class)->receive($in);
    expect((float) $in->fresh()->unit_cost)->toBe(25.0)->and((float) $in->fresh()->total_cost)->toBe(75.0);
});

it('values stock as quantity times current cost per warehouse or across all warehouses', function () {
    $product = Product::factory()->create(['cost' => 25]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => Warehouse::default()->id, 'quantity' => 6]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => Warehouse::factory()->create()->id, 'quantity' => 4]);
    $strategy = app(CostingStrategy::class);
    expect($strategy->stockValue($product))->toBe(250.0)
        ->and($strategy->stockValue($product, Warehouse::default()->id))->toBe(150.0);
});

it('changing a raw cost updates its recipe cost', function () {
    $raw = Product::factory()->create(['type' => 'raw_material', 'cost' => 20]);
    $menu = Product::factory()->create(['cost' => 40]);
    $menu->components()->attach($raw, ['quantity' => 2]);
    $in = InventoryItemMovement::factory()->create(['product_id' => $raw->id, 'quantity' => 10, 'unit_cost' => 30]);
    app(ImmediateCostingStrategy::class)->receive($in);
    expect((float) $menu->fresh()->cost)->toBe(60.0)
        ->and(ProductCostHistory::where('product_id', $raw->id)->count())->toBe(1)
        ->and(ProductCostHistory::where('product_id', $menu->id)->count())->toBe(1);
});
