<?php

use App\Enums\OrderStatus;
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
        'type' => 'takeaway',
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
        'type' => 'takeaway',
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
        'type' => 'dine_in',
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
        'type' => 'dine_in',
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
        'type' => 'takeaway',
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
        'type' => 'takeaway',
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

it('groups sales by main category with sub-category subtotals', function () {
    $main = Category::factory()->create(['name' => 'Drinks']);
    $hot = Category::factory()->create(['name' => 'Hot', 'parent_id' => $main->id]);
    $cold = Category::factory()->create(['name' => 'Cold', 'parent_id' => $main->id]);
    $water = Product::factory()->create([
        'category_id' => $main->id,
        'name' => 'Water',
        'price' => 10,
        'cost' => 2,
        'type' => ProductType::Consumable,
    ]);
    $tea = Product::factory()->create([
        'category_id' => $hot->id,
        'name' => 'Tea',
        'price' => 20,
        'cost' => 5,
        'type' => ProductType::Consumable,
    ]);
    $juice = Product::factory()->create([
        'category_id' => $cold->id,
        'name' => 'Juice',
        'price' => 30,
        'cost' => 8,
        'type' => ProductType::Consumable,
    ]);

    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'shift_id' => $this->shift->id,
        'status' => OrderStatus::COMPLETED,
        'type' => 'takeaway',
        'created_at' => Carbon::parse('2026-10-10 12:00:00'),
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $water->id,
        'quantity' => 1,
        'price' => 10,
        'cost' => 2,
        'total' => 10,
    ]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $tea->id,
        'quantity' => 2,
        'price' => 20,
        'cost' => 5,
        'total' => 40,
    ]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $juice->id,
        'quantity' => 1,
        'price' => 30,
        'cost' => 8,
        'total' => 30,
    ]);

    $grouped = $this->service->groupedProductSales('2026-10-10', '2026-10-10', [$this->shift->id]);
    $drinks = collect($grouped)->firstWhere('main_category_id', $main->id);

    expect($drinks)->not->toBeNull()
        ->and($drinks['total_sales'])->toBe(80.0)
        ->and($drinks['total_quantity'])->toBe(4.0)
        ->and($drinks['total_profit'])->toBe(60.0);

    $hotGroup = collect($drinks['sub_categories'])->firstWhere('sub_category_id', $hot->id);
    $coldGroup = collect($drinks['sub_categories'])->firstWhere('sub_category_id', $cold->id);
    $directGroup = collect($drinks['sub_categories'])->firstWhere('sub_category_id', null);

    expect($hotGroup['total_sales'])->toBe(40.0)
        ->and($hotGroup['products'])->toHaveCount(1)
        ->and($hotGroup['products'][0]['id'])->toBe($tea->id)
        ->and($coldGroup['total_sales'])->toBe(30.0)
        ->and($directGroup['total_sales'])->toBe(10.0)
        ->and($directGroup['products'][0]['name'])->toBe('Water');
});

it('category summary uses the main category', function () {
    $main = Category::factory()->create(['name' => 'Desserts']);
    $sub = Category::factory()->create(['name' => 'Cakes', 'parent_id' => $main->id]);
    $other = Category::factory()->create(['name' => 'Grill']);
    $cake = Product::factory()->create([
        'category_id' => $sub->id,
        'name' => 'Cake',
        'price' => 50,
        'cost' => 20,
        'type' => ProductType::Manufactured,
    ]);
    $grill = Product::factory()->create([
        'category_id' => $other->id,
        'name' => 'Kebab',
        'price' => 70,
        'cost' => 30,
        'type' => ProductType::Manufactured,
    ]);

    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'shift_id' => $this->shift->id,
        'status' => OrderStatus::COMPLETED,
        'type' => 'dine_in',
        'created_at' => Carbon::parse('2026-10-11 12:00:00'),
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $cake->id,
        'quantity' => 2,
        'price' => 50,
        'cost' => 20,
        'total' => 100,
    ]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $grill->id,
        'quantity' => 1,
        'price' => 70,
        'cost' => 30,
        'total' => 70,
    ]);

    $rows = $this->service->getCategoryPerformance(null, null, [$this->shift->id])->get();

    expect($rows->pluck('id'))->toContain($main->id)
        ->and($rows->pluck('id'))->toContain($other->id)
        ->and($rows->pluck('id'))->not->toContain($sub->id)
        ->and((float) $rows->firstWhere('id', $main->id)->total_sales)->toBe(100.0)
        ->and((float) $rows->firstWhere('id', $main->id)->total_profit)->toBe(60.0)
        ->and((float) $rows->firstWhere('id', $other->id)->total_sales)->toBe(70.0);
});

it('filters by main and sub category', function () {
    $main = Category::factory()->create(['name' => 'Kitchen']);
    $sub = Category::factory()->create(['name' => 'Soup', 'parent_id' => $main->id]);
    $other = Category::factory()->create(['name' => 'Bar']);
    $direct = Product::factory()->create([
        'category_id' => $main->id,
        'name' => 'Rice',
        'type' => ProductType::Consumable,
    ]);
    $soup = Product::factory()->create([
        'category_id' => $sub->id,
        'name' => 'Lentil',
        'type' => ProductType::Consumable,
    ]);
    $bar = Product::factory()->create([
        'category_id' => $other->id,
        'name' => 'Cola',
        'type' => ProductType::Consumable,
    ]);

    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'shift_id' => $this->shift->id,
        'status' => OrderStatus::COMPLETED,
        'type' => 'takeaway',
        'created_at' => Carbon::parse('2026-10-12 12:00:00'),
    ]);

    foreach ([[$direct, 15], [$soup, 25], [$bar, 12]] as [$product, $total]) {
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $total,
            'cost' => 1,
            'total' => $total,
        ]);
    }

    $bySub = $this->service->getProductsSalesPerformanceQuery(null, null, [$this->shift->id], null, $sub->id)->get();
    $byMain = $this->service->getProductsSalesPerformanceQuery(null, null, [$this->shift->id], $main->id)->get();
    $summary = $this->service->getCategoryPerformance(null, null, [$this->shift->id], $main->id, $sub->id)->get();

    expect($bySub)->toHaveCount(1)
        ->and($bySub->first()->id)->toBe($soup->id)
        ->and($byMain->pluck('id'))->toContain($direct->id)
        ->and($byMain->pluck('id'))->toContain($soup->id)
        ->and($byMain->pluck('id'))->not->toContain($bar->id)
        ->and($summary->pluck('id'))->toContain($main->id)
        ->and($summary->pluck('id'))->not->toContain($other->id)
        ->and((float) $summary->firstWhere('id', $main->id)->total_sales)->toBe(25.0);
});
