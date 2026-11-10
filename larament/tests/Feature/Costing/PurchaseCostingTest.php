<?php

use App\Costing\CostingMethod;
use App\Enums\MovementReason;
use App\Models\InventoryItem;
use App\Models\InventoryItemMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCostHistory;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\Warehouse;
use App\Services\PurchaseService;
use App\Services\SettingsService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(SettingsService::class)->set('costing_method', 'weighted_average');
    $this->product = Product::factory()->create(['type' => 'raw_material', 'cost' => 20]);
    InventoryItem::where('product_id', $this->product->id)->update(['quantity' => 10]);
    openInventoryDay($this->product);
});

it('closing an invoice averages 20 to 25 and records the invoice exactly once', function () {
    $invoice = PurchaseInvoice::factory()->create();
    PurchaseInvoiceItem::create(['purchase_invoice_id' => $invoice->id, 'product_id' => $this->product->id, 'quantity' => 10, 'price' => 30, 'total' => 300]);
    expect(app(PurchaseService::class)->closePurchaseInvoice($invoice))->toBeTrue();
    $history = ProductCostHistory::where('product_id', $this->product->id)->sole();
    $movement = InventoryItemMovement::where('product_id', $this->product->id)->sole();
    expect((float) $this->product->fresh()->cost)->toBe(25.0)
        ->and((float) $history->old_cost)->toBe(20.0)->and((float) $history->new_cost)->toBe(25.0)
        ->and($history->method)->toBe(CostingMethod::WeightedAverage)->and($history->source->is($invoice))->toBeTrue()
        ->and((float) $movement->unit_cost)->toBe(30.0)->and((float) $movement->total_cost)->toBe(300.0)
        ->and(app(StockService::class)->getCurrentStock($this->product->id))->toBe(20.0);
});

it('sale movements carry current cost even if a different price is supplied', function () {
    $out = app(StockService::class)->removeStock([['product_id' => $this->product->id, 'quantity' => 3, 'unit_cost' => 99]], MovementReason::ORDER)->sole();
    expect((float) $out->unit_cost)->toBe(20.0)->and((float) $out->total_cost)->toBe(60.0)
        ->and((float) $this->product->fresh()->cost)->toBe(20.0)->and(ProductCostHistory::count())->toBe(0);
});

it('nested menu cost follows a raw change two levels up and retains the source', function () {
    $middle = Product::factory()->create(['cost' => 40]);
    $top = Product::factory()->create(['cost' => 120]);
    $middle->components()->attach($this->product, ['quantity' => 2]);
    $top->components()->attach($middle, ['quantity' => 3]);
    $invoice = PurchaseInvoice::factory()->create();
    app(StockService::class)->addStock([['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 30]], MovementReason::PURCHASE, $invoice);
    expect((float) $middle->fresh()->cost)->toBe(50.0)->and((float) $top->fresh()->cost)->toBe(150.0)
        ->and(ProductCostHistory::count())->toBe(3)
        ->and(ProductCostHistory::where('product_id', $top->id)->sole()->source->is($invoice))->toBeTrue();
});

it('stops recipe cascades at a cycle', function () {
    $a = Product::factory()->create(['cost' => 20]);
    $b = Product::factory()->create(['cost' => 20]);
    $a->components()->attach($this->product, ['quantity' => 1]);
    $a->components()->attach($b, ['quantity' => 1]);
    $b->components()->attach($a, ['quantity' => 1]);
    $a->updateManufacturedCost();
    expect((float) $a->fresh()->cost)->toBe(40.0)->and((float) $b->fresh()->cost)->toBe(40.0);
});

it('finds original sale cost using the temporary order id and warehouse', function (string $method, float $cost) {
    app(SettingsService::class)->set('costing_method', $method);
    $this->product->update(['cost' => 25]);
    ProductCostHistory::query()->delete();
    $order = Order::factory()->create();
    $other = Warehouse::factory()->create();
    InventoryItemMovement::factory()->create(['product_id' => $this->product->id, 'operation' => 'out', 'reason' => MovementReason::ORDER, 'quantity' => 2, 'unit_cost' => 99, 'referenceable_type' => $order->getMorphClass(), 'referenceable_id' => $order->id, 'warehouse_id' => $other->id]);
    InventoryItemMovement::factory()->create(['product_id' => $this->product->id, 'operation' => 'out', 'reason' => MovementReason::ORDER, 'quantity' => 2, 'unit_cost' => 19, 'referenceable_type' => $order->getMorphClass(), 'referenceable_id' => $order->id]);
    $temporary = new Order;
    $temporary->id = $order->id;
    $in = app(StockService::class)->addStock([['product_id' => $this->product->id, 'quantity' => 2]], MovementReason::ORDER_RETURN, $temporary)->sole();
    expect((float) $in->unit_cost)->toBe(19.0)->and((float) $in->total_cost)->toBe(38.0)
        ->and((float) $this->product->fresh()->cost)->toBe($cost);
    if ($method === 'weighted_average') {
        expect(ProductCostHistory::sole()->source->is($order))->toBeTrue();
    } else {
        expect(ProductCostHistory::count())->toBe(0);
    }
})->with([['weighted_average', 24.0], ['immediate', 25.0], ['off', 25.0]]);

it('falls back to current cost for returns without an original sale', function () {
    $in = app(StockService::class)->addStock([['product_id' => $this->product->id, 'quantity' => 2, 'unit_cost' => 99]], MovementReason::ORDER_RETURN, Order::factory()->create())->sole();
    expect((float) $in->unit_cost)->toBe(20.0)->and((float) $this->product->fresh()->cost)->toBe(20.0)
        ->and(ProductCostHistory::count())->toBe(0);
});

it('costs sequential receipts into different warehouses against the preceding receipt', function () {
    $grill = Warehouse::factory()->create();
    app(StockService::class)->addStock([
        ['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 30],
        ['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 40, 'warehouse_id' => $grill->id],
    ], MovementReason::PURCHASE);
    expect((float) $this->product->fresh()->cost)->toBe(30.0)
        ->and(app(StockService::class)->getCurrentStock($this->product->id))->toBe(30.0);
});

it('merges duplicate invoice lines at their weighted price and costs them once', function () {
    app(StockService::class)->addStock([
        ['product_id' => $this->product->id, 'quantity' => 5, 'unit_cost' => 20],
        ['product_id' => $this->product->id, 'quantity' => 5, 'unit_cost' => 40],
    ], MovementReason::PURCHASE);
    expect((float) $this->product->fresh()->cost)->toBe(25.0)->and(ProductCostHistory::count())->toBe(1)
        ->and((float) InventoryItemMovement::sole()->unit_cost)->toBe(30.0);
});

it('keeps transfer costs without calling the strategy', function () {
    $strategy = Mockery::mock(\App\Costing\CostingStrategy::class);
    $strategy->shouldNotReceive('receive', 'consume', 'reverse');
    app()->instance(\App\Costing\CostingStrategy::class, $strategy);
    $stock = app(StockService::class);
    $stock->addStock([['product_id' => $this->product->id, 'quantity' => 2, 'unit_cost' => 30]], MovementReason::TRANSFER_IN);
    $stock->removeStock([['product_id' => $this->product->id, 'quantity' => 2, 'unit_cost' => 30]], MovementReason::TRANSFER_OUT);
    expect((float) $this->product->fresh()->cost)->toBe(20.0)->and(ProductCostHistory::count())->toBe(0);
});

it('rolls back balances, movement, invoice and history together when costing fails', function () {
    $invoice = PurchaseInvoice::factory()->create();
    PurchaseInvoiceItem::create(['purchase_invoice_id' => $invoice->id, 'product_id' => $this->product->id, 'quantity' => 10, 'price' => 30, 'total' => 300]);
    Event::listen('eloquent.created: '.ProductCostHistory::class, function () {
        throw new RuntimeException('History persistence failed');
    });
    try {
        expect(fn () => app(PurchaseService::class)->closePurchaseInvoice($invoice))->toThrow(Exception::class, 'History persistence failed');
    } finally {
        Event::forget('eloquent.created: '.ProductCostHistory::class);
    }
    expect((float) $this->product->fresh()->cost)->toBe(20.0)
        ->and(app(StockService::class)->getCurrentStock($this->product->id))->toBe(10.0)
        ->and($invoice->fresh()->closed_at)->toBeNull()
        ->and(InventoryItemMovement::count())->toBe(0)->and(ProductCostHistory::count())->toBe(0);
});

it('stores the four decimal average and matching history', function () {
    app(StockService::class)->addStock([['product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => 30]], MovementReason::PURCHASE);
    expect((float) $this->product->fresh()->cost)->toBe(20.9091)
        ->and((float) ProductCostHistory::sole()->new_cost)->toBe(20.9091);
});
