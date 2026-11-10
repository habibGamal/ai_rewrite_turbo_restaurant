<?php

use App\Enums\MovementReason;
use App\Models\InventoryItem;
use App\Models\InventoryItemMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Shift;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\PurchaseService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $user = User::factory()->admin()->create();
    $this->actingAs($user);
    $shift = Shift::factory()->active()->create(['user_id' => $user->id]);
    $this->order = Order::factory()->create([
        'user_id' => $user->id, 'shift_id' => $shift->id, 'type' => 'takeaway',
        'discount' => 0, 'temp_discount_percent' => 0,
    ]);
    app(SettingsService::class)->set('costing_method', 'weighted_average');
});

function realCostItem(Order $order, Product $product, float $quantity = 1, float $cost = 20): \App\Models\OrderItem
{
    return $order->items()->create([
        'product_id' => $product->id, 'quantity' => $quantity,
        'price' => 100, 'total' => 100 * $quantity, 'cost' => $cost,
    ]);
}

it('writes the real cost after a purchase closes mid-shift under weighted average', function () {
    $product = Product::factory()->create(['type' => 'consumable', 'cost' => 20]);
    InventoryItem::where('product_id', $product->id)->update(['quantity' => 10]);
    openInventoryDay($product);
    $item = realCostItem($this->order, $product, 2);
    $invoice = PurchaseInvoice::factory()->create();
    $invoice->items()->create(['product_id' => $product->id, 'quantity' => 10, 'price' => 26, 'total' => 260]);
    app(PurchaseService::class)->closePurchaseInvoice($invoice);

    app(OrderService::class)->completeOrder($this->order->id, ['cash' => 200]);

    expect((float) $item->fresh()->cost)->toBe(23.0)
        ->and((float) InventoryItemMovement::where('reason', MovementReason::ORDER)->sole()->total_cost)->toBe(46.0);
});

it('splits a shared nested component by each order items quantity contribution', function () {
    $bun = Product::factory()->create(['type' => 'raw_material', 'cost' => 6]);
    $cheese = Product::factory()->create(['type' => 'raw_material', 'cost' => 4]);
    $burger = Product::factory()->create(['type' => 'manufactured']);
    $double = Product::factory()->create(['type' => 'manufactured']);
    $burger->components()->attach($bun, ['quantity' => 1]);
    $double->components()->attach($burger, ['quantity' => 2]);
    $double->components()->attach($cheese, ['quantity' => 1]);
    openInventoryDay($bun);
    $first = realCostItem($this->order, $burger, 2);
    $second = realCostItem($this->order, $double);

    app(OrderService::class)->completeOrder($this->order->id, ['cash' => 300]);

    expect((float) $first->fresh()->cost)->toBe(6.0)
        ->and((float) $second->fresh()->cost)->toBe(16.0)
        ->and((float) InventoryItemMovement::where('product_id', $bun->id)->sole()->quantity)->toBe(4.0);
});

it('recalculates profit using the real costs and existing discount calculation', function () {
    $product = Product::factory()->create(['type' => 'consumable', 'cost' => 23]);
    openInventoryDay($product);
    realCostItem($this->order, $product, 2);
    $this->order->update(['discount' => 10]);

    $completed = app(OrderService::class)->completeOrder($this->order->id, ['cash' => 190]);

    expect((float) $completed->profit)->toBe(144.0)->and((float) $completed->total)->toBe(190.0);
});

it('does not rewrite cost when a completed order is cancelled', function () {
    $product = Product::factory()->create(['type' => 'consumable', 'cost' => 23]);
    openInventoryDay($product);
    $item = realCostItem($this->order, $product);
    app(OrderService::class)->completeOrder($this->order->id, ['cash' => 100]);
    $product->update(['cost' => 40]);

    app(OrderService::class)->cancelOrder($this->order->id);

    expect((float) $item->fresh()->cost)->toBe(23.0);
});

it('keeps the old cost of an item whose recipe produces no movements', function () {
    $product = Product::factory()->create(['type' => 'manufactured']);
    $item = realCostItem($this->order, $product, 1, 17);
    app(OrderService::class)->completeOrder($this->order->id, ['cash' => 100]);
    expect((float) $item->fresh()->cost)->toBe(17.0);
});
