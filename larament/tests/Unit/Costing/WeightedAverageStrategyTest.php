<?php

use App\Costing\CostingMethod;
use App\Costing\CostingStrategy;
use App\Costing\Strategies\WeightedAverageCostingStrategy;
use App\Enums\MovementReason;
use App\Models\InventoryItem;
use App\Models\InventoryItemMovement;
use App\Models\Product;
use App\Models\ProductCostHistory;
use App\Models\Warehouse;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->product = Product::factory()->create(['cost' => 20]);
    InventoryItem::create(['product_id' => $this->product->id, 'warehouse_id' => Warehouse::default()->id, 'quantity' => 10]);
});

it('averages 10 at 20 plus 10 at 30 to 25 and records the change', function () {
    $in = InventoryItemMovement::factory()->create(['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 30]);
    app(WeightedAverageCostingStrategy::class)->receive($in);
    expect((float) $this->product->fresh()->cost)->toBe(25.0)
        ->and((float) ProductCostHistory::sole()->old_cost)->toBe(20.0)
        ->and((float) ProductCostHistory::sole()->new_cost)->toBe(25.0);
});

it('uses Main 6 plus Grill 4 at 20 before receiving 10 at 30 to get 25', function () {
    InventoryItem::where('product_id', $this->product->id)->update(['quantity' => 6]);
    $grill = Warehouse::factory()->create(['name' => 'Grill']);
    InventoryItem::create(['product_id' => $this->product->id, 'warehouse_id' => $grill->id, 'quantity' => 4]);
    $in = InventoryItemMovement::factory()->create(['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 30, 'warehouse_id' => $grill->id]);
    app(WeightedAverageCostingStrategy::class)->receive($in);
    expect((float) $this->product->fresh()->cost)->toBe(25.0);
});

it('treats negative 5 as zero so 10 at 30 makes cost 30', function () {
    InventoryItem::where('product_id', $this->product->id)->update(['quantity' => -5]);
    $in = InventoryItemMovement::factory()->create(['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 30]);
    app(WeightedAverageCostingStrategy::class)->receive($in);
    expect((float) $this->product->fresh()->cost)->toBe(30.0);
});

it('includes a free line so 10 at 20 plus 10 at zero makes cost 10', function () {
    $in = InventoryItemMovement::factory()->create(['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 0]);
    app(WeightedAverageCostingStrategy::class)->receive($in);
    expect((float) $this->product->fresh()->cost)->toBe(10.0)
        ->and((float) $in->fresh()->unit_cost)->toBe(0.0)
        ->and((float) ProductCostHistory::sole()->new_cost)->toBe(10.0);
});

it('does not change average for outgoing movements', function (MovementReason $reason) {
    $out = InventoryItemMovement::factory()->create(['product_id' => $this->product->id, 'quantity' => 3, 'unit_cost' => 30, 'reason' => $reason, 'operation' => 'out']);
    app(WeightedAverageCostingStrategy::class)->consume($out);
    expect((float) $this->product->fresh()->cost)->toBe(20.0)
        ->and((float) $out->fresh()->total_cost)->toBe(60.0)->and(ProductCostHistory::count())->toBe(0);
})->with([MovementReason::ORDER, MovementReason::PURCHASE_RETURN, MovementReason::WASTE]);

it('values stocktaking surplus and other receipts at current cost without changing average', function (MovementReason $reason) {
    $in = InventoryItemMovement::factory()->create(['product_id' => $this->product->id, 'quantity' => 3, 'unit_cost' => 99, 'reason' => $reason]);
    app(WeightedAverageCostingStrategy::class)->receive($in);
    expect((float) $this->product->fresh()->cost)->toBe(20.0)
        ->and((float) $in->fresh()->unit_cost)->toBe(20.0)->and(ProductCostHistory::count())->toBe(0);
})->with([MovementReason::STOCKTAKING, MovementReason::MANUAL, MovementReason::ADJUSTMENT, MovementReason::ORDER_RETURN]);

it('reaverages 2 returned at the original sale cost 19 into 10 at 25 to get 24', function () {
    $this->product->cost = 25;
    $this->product->save();
    $original = InventoryItemMovement::factory()->create(['product_id' => $this->product->id, 'quantity' => 2, 'unit_cost' => 19, 'reason' => MovementReason::ORDER, 'operation' => 'out']);
    $in = InventoryItemMovement::factory()->create(['product_id' => $this->product->id, 'quantity' => 2, 'unit_cost' => 99, 'reason' => MovementReason::ORDER_RETURN]);
    app(WeightedAverageCostingStrategy::class)->reverse($original, $in);
    expect((float) $this->product->fresh()->cost)->toBe(24.0)
        ->and((float) $in->fresh()->unit_cost)->toBe(19.0)->and((float) $in->fresh()->total_cost)->toBe(38.0);
});

it('locks the product row inside a transaction before recalculating', function () {
    $locked = false;
    Product::addGlobalScope('costing_lock_assertion', function (Builder $builder) use (&$locked) {
        if ($builder->getQuery()->lock === true && DB::transactionLevel() > 0) {
            $locked = true;
        }
    });
    $in = InventoryItemMovement::factory()->create(['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 30]);
    app(WeightedAverageCostingStrategy::class)->receive($in);
    expect($locked)->toBeTrue();
});

it('resolves weighted average from the container', function () {
    app(SettingsService::class)->set('costing_method', 'weighted_average');
    expect(app(CostingStrategy::class))->toBeInstanceOf(WeightedAverageCostingStrategy::class)
        ->and(app(CostingStrategy::class)->method())->toBe(CostingMethod::WeightedAverage);
});
