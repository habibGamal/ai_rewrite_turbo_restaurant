<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Services\ProductsSalesReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->service = new ProductsSalesReportService;
    $this->user = User::factory()->create();
    $this->shift = Shift::factory()->create(['user_id' => $this->user->id]);
    $this->category = Category::factory()->create(['name' => 'Hot Drinks']);

    $this->productA = Product::factory()->create([
        'category_id' => $this->category->id,
        'name' => 'Espresso',
        'price' => 50.00,
        'cost' => 20.00,
        'type' => ProductType::Manufactured,
    ]);

    $this->productB = Product::factory()->create([
        'category_id' => $this->category->id,
        'name' => 'Tea',
        'price' => 30.00,
        'cost' => 10.00,
        'type' => ProductType::Consumable,
    ]);

    // An unsold product in catalog
    $this->productUnsold = Product::factory()->create([
        'category_id' => $this->category->id,
        'name' => 'Water',
        'price' => 10.00,
        'cost' => 5.00,
        'type' => ProductType::Consumable,
    ]);
});

it('excludes cancelled orders from period summary and only counts sold products', function () {
    $date = Carbon::parse('2026-10-10 12:00:00');

    // 1 completed order with productA (qty: 2)
    $completedOrder = Order::factory()->create([
        'user_id' => $this->user->id,
        'shift_id' => $this->shift->id,
        'status' => OrderStatus::COMPLETED,
        'type' => OrderType::TAKEAWAY,
        'total' => 100.00,
        'profit' => 60.00,
        'created_at' => $date,
    ]);

    OrderItem::factory()->create([
        'order_id' => $completedOrder->id,
        'product_id' => $this->productA->id,
        'quantity' => 2,
        'price' => 50.00,
        'cost' => 20.00,
        'total' => 100.00,
    ]);

    // 1 cancelled order with productB (qty: 1)
    $cancelledOrder = Order::factory()->create([
        'user_id' => $this->user->id,
        'shift_id' => $this->shift->id,
        'status' => OrderStatus::CANCELLED,
        'type' => OrderType::TAKEAWAY,
        'total' => 30.00,
        'profit' => 0.00,
        'created_at' => $date,
    ]);

    OrderItem::factory()->create([
        'order_id' => $cancelledOrder->id,
        'product_id' => $this->productB->id,
        'quantity' => 1,
        'price' => 30.00,
        'cost' => 10.00,
        'total' => 30.00,
    ]);

    $summary = $this->service->getPeriodSummary('2026-10-10', '2026-10-10', [$this->shift->id]);

    // Only productA should be counted as sold, not productB (cancelled) or productUnsold (unsold)
    expect($summary['total_products'])->toBe(1);
    expect($summary['total_sales'])->toBe(100.0);
    expect($summary['total_profit'])->toBe(60.0);
    expect($summary['total_quantity'])->toBe(2.0);
    expect($summary['best_selling_product']->id)->toBe($this->productA->id);
    expect($summary['most_profitable_product']->id)->toBe($this->productA->id);
});

it('excludes cancelled orders from products sales performance query', function () {
    $date = Carbon::parse('2026-10-10 12:00:00');

    // 1 completed order
    $completedOrder = Order::factory()->create([
        'user_id' => $this->user->id,
        'shift_id' => $this->shift->id,
        'status' => OrderStatus::COMPLETED,
        'type' => OrderType::DINE_IN,
        'total' => 100.00,
        'profit' => 60.00,
        'created_at' => $date,
    ]);

    OrderItem::factory()->create([
        'order_id' => $completedOrder->id,
        'product_id' => $this->productA->id,
        'quantity' => 2,
        'price' => 50.00,
        'cost' => 20.00,
        'total' => 100.00,
    ]);

    // 1 cancelled order
    $cancelledOrder = Order::factory()->create([
        'user_id' => $this->user->id,
        'shift_id' => $this->shift->id,
        'status' => OrderStatus::CANCELLED,
        'type' => OrderType::DINE_IN,
        'total' => 30.00,
        'profit' => 0.00,
        'created_at' => $date,
    ]);

    OrderItem::factory()->create([
        'order_id' => $cancelledOrder->id,
        'product_id' => $this->productB->id,
        'quantity' => 1,
        'price' => 30.00,
        'cost' => 10.00,
        'total' => 30.00,
    ]);

    $results = $this->service->getProductsSalesPerformanceQuery(null, null, [$this->shift->id])->get();

    // Only productA should be returned because productB was only in a cancelled order
    expect($results)->toHaveCount(1);
    expect($results->first()->id)->toBe($this->productA->id);
    expect((float) $results->first()->total_sales)->toBe(100.0);
    expect((float) $results->first()->total_profit)->toBe(60.0);
    expect((float) $results->first()->total_quantity)->toBe(2.0);
});

it('excludes cancelled orders from category performance query', function () {
    $date = Carbon::parse('2026-10-10 12:00:00');

    // 1 completed order
    $completedOrder = Order::factory()->create([
        'user_id' => $this->user->id,
        'shift_id' => $this->shift->id,
        'status' => OrderStatus::COMPLETED,
        'type' => OrderType::TAKEAWAY,
        'total' => 100.00,
        'profit' => 60.00,
        'created_at' => $date,
    ]);

    OrderItem::factory()->create([
        'order_id' => $completedOrder->id,
        'product_id' => $this->productA->id,
        'quantity' => 2,
        'price' => 50.00,
        'cost' => 20.00,
        'total' => 100.00,
    ]);

    // 1 cancelled order
    $cancelledOrder = Order::factory()->create([
        'user_id' => $this->user->id,
        'shift_id' => $this->shift->id,
        'status' => OrderStatus::CANCELLED,
        'type' => OrderType::TAKEAWAY,
        'total' => 30.00,
        'profit' => 0.00,
        'created_at' => $date,
    ]);

    OrderItem::factory()->create([
        'order_id' => $cancelledOrder->id,
        'product_id' => $this->productB->id,
        'quantity' => 1,
        'price' => 30.00,
        'cost' => 10.00,
        'total' => 30.00,
    ]);

    $categories = $this->service->getCategoryPerformance(null, null, [$this->shift->id])->get();
    $cat = $categories->firstWhere('id', $this->category->id);

    expect((float) $cat->total_sales)->toBe(100.0);
    expect((float) $cat->total_profit)->toBe(60.0);
    expect((float) $cat->total_quantity)->toBe(2.0);
});
