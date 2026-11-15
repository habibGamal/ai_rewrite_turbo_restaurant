<?php

use App\Enums\OrderStatus;
use App\Models\ExpenceType;
use App\Models\Expense;
use App\Models\InventoryItemMovementDaily;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Waste;
use App\Sync\BuilderRegistry;
use App\Sync\Builders\DaySummaryBuilder;
use App\Sync\DocumentType;

it('builds a day_summary snapshot for a business date', function () {
    $shift = Shift::factory()->create(['business_date' => '2026-10-29']);
    $product = Product::factory()->create();

    $order = Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 100.00,
        'tax' => 10.00,
        'discount' => 5.00,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 2.000,
        'cost' => 25.00,
    ]);

    $expenseType = ExpenceType::factory()->create();

    Expense::factory()->create([
        'shift_id' => $shift->id,
        'expence_type_id' => $expenseType->id,
        'amount' => 30.00,
    ]);

    Waste::factory()->create([
        'business_date' => '2026-10-29',
        'total' => 15.00,
        'closed_at' => now(),
    ]);

    $builder = app(DaySummaryBuilder::class);
    $data = $builder->withKey('2026-10-29')->build('dummy-uuid');

    expect($data)->toBe([
        'date' => '2026-10-29',
        'orders_count' => 1,
        'completed_count' => 1,
        'net_total' => '90.00',
        'discount_total' => '5.00',
        'cogs_total' => '50.00',
        'expenses_total' => '30.00',
        'revenues_total' => '0.00',
        'fees_total' => '0.00',
        'waste_total' => '15.00',
        'refunds_total' => '0.00',
        'refunds_cash' => '0.00',
        'guests_total' => 0,
        'equipment_total' => '0.00',
    ]);
});

it('returns zeros for day with closed inventory movement but no orders, expenses, or waste', function () {
    $product = Product::factory()->create();
    InventoryItemMovementDaily::create([
        'product_id' => $product->id,
        'date' => '2026-10-29',
        'start_quantity' => 10.0,
        'incoming_quantity' => 0.0,
        'sales_quantity' => 0.0,
        'return_sales_quantity' => 0.0,
        'return_waste_quantity' => 0.0,
        'end_quantity' => 10.0,
        'closed_at' => now(),
    ]);

    $builder = app(DaySummaryBuilder::class);
    $data = $builder->withKey('2026-10-29')->build('dummy-uuid');

    expect($data)->toBe([
        'date' => '2026-10-29',
        'orders_count' => 0,
        'completed_count' => 0,
        'net_total' => '0.00',
        'discount_total' => '0.00',
        'cogs_total' => '0.00',
        'expenses_total' => '0.00',
        'revenues_total' => '0.00',
        'fees_total' => '0.00',
        'waste_total' => '0.00',
        'refunds_total' => '0.00',
        'refunds_cash' => '0.00',
        'guests_total' => 0,
        'equipment_total' => '0.00',
    ]);
});

it('returns null when key is null or no data exists for the date', function () {
    $builder = app(DaySummaryBuilder::class);

    // No key
    expect($builder->build('dummy-uuid'))->toBeNull();

    // Key with no data
    expect($builder->withKey('2099-01-01')->build('dummy-uuid'))->toBeNull();
});

it('is registered in BuilderRegistry and returns DaySummary type', function () {
    $builder = BuilderRegistry::for(DocumentType::DaySummary);

    expect($builder)->toBeInstanceOf(DaySummaryBuilder::class)
        ->and($builder->type())->toBe(DocumentType::DaySummary);
});

it('includes nonzero item discounts in discount_total for completed orders only', function () {
    $shift = Shift::factory()->create(['business_date' => '2026-10-29']);
    $productA = Product::factory()->create();
    $productB = Product::factory()->create();

    // Completed order with order-level discount of 15.00
    $completedOrder = Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 180.00,
        'tax' => 10.00,
        'discount' => 15.00,
    ]);

    // Item 1 with 7.50 item discount
    OrderItem::factory()->create([
        'order_id' => $completedOrder->id,
        'product_id' => $productA->id,
        'quantity' => 2.000,
        'cost' => 20.00,
        'item_discount' => 7.50,
    ]);

    // Item 2 with 3.25 item discount
    OrderItem::factory()->create([
        'order_id' => $completedOrder->id,
        'product_id' => $productB->id,
        'quantity' => 1.000,
        'cost' => 15.00,
        'item_discount' => 3.25,
    ]);

    // Cancelled order with discounts - should NOT be included in discount_total
    $cancelledOrder = Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => OrderStatus::CANCELLED,
        'total' => 100.00,
        'discount' => 20.00,
    ]);

    OrderItem::factory()->create([
        'order_id' => $cancelledOrder->id,
        'product_id' => $productA->id,
        'quantity' => 1.000,
        'cost' => 20.00,
        'item_discount' => 50.00,
    ]);

    $builder = app(DaySummaryBuilder::class);
    $data = $builder->withKey('2026-10-29')->build('dummy-uuid');

    // Expected discount_total: 15.00 (order discount) + 7.50 + 3.25 = 25.75
    expect($data)->not->toBeNull()
        ->and($data['discount_total'])->toBe('25.75')
        ->and($data['orders_count'])->toBe(2)
        ->and($data['completed_count'])->toBe(1);
});
