<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ProductType;
use App\Enums\UserRole;
use App\Filament\Pages\Reports\ProductsSalesPerformanceReport;
use App\Filament\Widgets\CategoryPerformanceWidget;
use App\Filament\Widgets\NoProductsSalesInPeriodWidget;
use App\Filament\Widgets\OrderTypePerformanceWidget;
use App\Filament\Widgets\ProductsSalesStatsWidget;
use App\Filament\Widgets\ProductsSalesTableWidget;
use App\Filament\Widgets\TopProductsByProfitWidget;
use App\Filament\Widgets\TopProductsBySalesWidget;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create admin user for authentication (ViewerAccess trait allows viewers and admins)
    $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    actingAs($this->admin);
});

// Page Rendering Tests
it('can render the products sales performance report page', function () {
    livewire(ProductsSalesPerformanceReport::class)
        ->assertSuccessful();
});

// Access Control Tests
it('can access page with admin role', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    actingAs($admin);

    livewire(ProductsSalesPerformanceReport::class)
        ->assertSuccessful();
});

it('can access page with viewer role', function () {
    $viewer = User::factory()->create(['role' => UserRole::VIEWER]);
    actingAs($viewer);

    livewire(ProductsSalesPerformanceReport::class)
        ->assertSuccessful();
});

it('cannot access page with cashier role', function () {
    $cashier = User::factory()->create(['role' => UserRole::CASHIER]);
    actingAs($cashier);

    livewire(ProductsSalesPerformanceReport::class)
        ->assertForbidden();
});

// Filter Form Tests
it('has filter form with period selection', function () {
    livewire(ProductsSalesPerformanceReport::class)
        ->assertSchemaExists()
        ->assertSchemaComponentExists('presetPeriod')
        ->assertSchemaComponentExists('startDate')
        ->assertSchemaComponentExists('endDate');
});

it('can set custom date range filter', function () {
    $startDate = now()->subDays(7)->startOfDay()->toDateString();
    $endDate = now()->endOfDay()->toDateString();

    livewire(ProductsSalesPerformanceReport::class)
        ->fillForm([
            'presetPeriod' => 'custom',
            'startDate' => $startDate,
            'endDate' => $endDate,
        ])
        ->assertSchemaStateSet([
            'presetPeriod' => 'custom',
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]);
});

it('defaults to last 30 days preset', function () {
    livewire(ProductsSalesPerformanceReport::class)
        ->assertSchemaStateSet([
            'presetPeriod' => 'last_30_days',
        ]);
});

// Widget Rendering Tests - No Data Scenario
it('shows no products sales widget when there are no completed orders', function () {
    livewire(ProductsSalesPerformanceReport::class)
        ->assertSeeLivewire(NoProductsSalesInPeriodWidget::class);
});

it('does not show performance widgets when there are no completed orders', function () {
    livewire(ProductsSalesPerformanceReport::class)
        ->assertDontSeeLivewire(ProductsSalesStatsWidget::class)
        ->assertDontSeeLivewire(TopProductsBySalesWidget::class)
        ->assertDontSeeLivewire(TopProductsByProfitWidget::class)
        ->assertDontSeeLivewire(OrderTypePerformanceWidget::class)
        ->assertDontSeeLivewire(CategoryPerformanceWidget::class)
        ->assertDontSeeLivewire(ProductsSalesTableWidget::class);
});

// Widget Rendering Tests - With Data
it('shows performance widgets when there are completed orders with products', function () {
    // Create test data
    $category = Category::factory()->create();
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'price' => 100,
        'cost' => 50,
        'type' => ProductType::CONSUMABLE,
    ]);

    // Create completed order with product
    $order = Order::factory()->create([
        'type' => OrderType::DINE_IN,
        'status' => OrderStatus::COMPLETED,
        'created_at' => now(),
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'price' => $product->price,
        'cost' => $product->cost,
        'total' => $product->price * 2,
    ]);

    livewire(ProductsSalesPerformanceReport::class)
        ->assertSeeLivewire(ProductsSalesStatsWidget::class)
        ->assertSeeLivewire(TopProductsBySalesWidget::class)
        ->assertSeeLivewire(TopProductsByProfitWidget::class)
        ->assertSeeLivewire(OrderTypePerformanceWidget::class)
        ->assertSeeLivewire(CategoryPerformanceWidget::class)
        ->assertSeeLivewire(ProductsSalesTableWidget::class)
        ->assertDontSeeLivewire(NoProductsSalesInPeriodWidget::class);
});

it('does not show performance widgets for pending orders', function () {
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);

    // Create pending order (not completed)
    $pendingOrder = Order::factory()->create([
        'type' => OrderType::DELIVERY,
        'status' => OrderStatus::PENDING,
        'created_at' => now(),
    ]);

    OrderItem::factory()->create([
        'order_id' => $pendingOrder->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'price' => $product->price,
        'cost' => $product->cost,
        'total' => $product->price * 2,
    ]);

    livewire(ProductsSalesPerformanceReport::class)
        ->assertSeeLivewire(NoProductsSalesInPeriodWidget::class)
        ->assertDontSeeLivewire(ProductsSalesStatsWidget::class);
});

it('does not show performance widgets for cancelled orders', function () {
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);

    // Create cancelled order
    $cancelledOrder = Order::factory()->create([
        'type' => OrderType::DELIVERY,
        'status' => OrderStatus::CANCELLED,
        'created_at' => now(),
    ]);

    OrderItem::factory()->create([
        'order_id' => $cancelledOrder->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'price' => $product->price,
        'cost' => $product->cost,
        'total' => $product->price * 2,
    ]);

    livewire(ProductsSalesPerformanceReport::class)
        ->assertSeeLivewire(NoProductsSalesInPeriodWidget::class)
        ->assertDontSeeLivewire(ProductsSalesStatsWidget::class);
});

// Filter Functionality Tests
it('filters data by date range', function () {
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);

    // Create order within date range
    $recentOrder = Order::factory()->create([
        'type' => OrderType::DELIVERY,
        'status' => OrderStatus::COMPLETED,
        'created_at' => now()->subDays(5),
    ]);

    OrderItem::factory()->create([
        'order_id' => $recentOrder->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'price' => $product->price,
        'cost' => $product->cost,
        'total' => $product->price * 2,
    ]);

    // Create order outside date range
    $oldOrder = Order::factory()->create([
        'type' => OrderType::DELIVERY,
        'status' => OrderStatus::COMPLETED,
        'created_at' => now()->subDays(40),
    ]);

    OrderItem::factory()->create([
        'order_id' => $oldOrder->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => $product->price,
        'cost' => $product->cost,
        'total' => $product->price,
    ]);

    $startDate = now()->subDays(7)->startOfDay()->toDateString();
    $endDate = now()->endOfDay()->toDateString();

    livewire(ProductsSalesPerformanceReport::class)
        ->fillForm([
            'presetPeriod' => 'custom',
            'startDate' => $startDate,
            'endDate' => $endDate,
        ])
        ->assertSeeLivewire(ProductsSalesStatsWidget::class);
});

it('can filter by today', function () {
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);

    // Create order today
    $todayOrder = Order::factory()->create([
        'type' => OrderType::DELIVERY,
        'status' => OrderStatus::COMPLETED,
        'created_at' => now(),
    ]);

    OrderItem::factory()->create([
        'order_id' => $todayOrder->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'price' => $product->price,
        'cost' => $product->cost,
        'total' => $product->price * 2,
    ]);

    livewire(ProductsSalesPerformanceReport::class)
        ->fillForm(['presetPeriod' => 'today'])
        ->assertSeeLivewire(ProductsSalesStatsWidget::class);
});

it('can filter by yesterday', function () {
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);

    // Create order yesterday
    $yesterdayOrder = Order::factory()->create([
        'type' => OrderType::DELIVERY,
        'status' => OrderStatus::COMPLETED,
        'created_at' => now()->subDay(),
    ]);

    OrderItem::factory()->create([
        'order_id' => $yesterdayOrder->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'price' => $product->price,
        'cost' => $product->cost,
        'total' => $product->price * 2,
    ]);

    livewire(ProductsSalesPerformanceReport::class)
        ->fillForm(['presetPeriod' => 'yesterday'])
        ->assertSeeLivewire(ProductsSalesStatsWidget::class);
});

it('can filter by last 7 days', function () {
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);

    // Create order within last 7 days
    $recentOrder = Order::factory()->create([
        'type' => OrderType::DELIVERY,
        'status' => OrderStatus::COMPLETED,
        'created_at' => now()->subDays(3),
    ]);

    OrderItem::factory()->create([
        'order_id' => $recentOrder->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'price' => $product->price,
        'cost' => $product->cost,
        'total' => $product->price * 2,
    ]);

    livewire(ProductsSalesPerformanceReport::class)
        ->fillForm(['presetPeriod' => 'last_7_days'])
        ->assertSeeLivewire(ProductsSalesStatsWidget::class);
});

// Order Type Coverage Tests
it('includes all order types in performance widgets', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'price' => 100,
        'cost' => 50,
        'type' => ProductType::Consumable,
    ]);

    // Create orders for each order type
    $orderTypes = [
        OrderType::DINE_IN,
        OrderType::TAKEAWAY,
        OrderType::DELIVERY,
        OrderType::WEB_DELIVERY,
        OrderType::WEB_TAKEAWAY,
        OrderType::TALABAT,
        OrderType::COMPANIES,
    ];

    foreach ($orderTypes as $orderType) {
        $order = Order::factory()->create([
            'type' => $orderType,
            'status' => OrderStatus::COMPLETED,
            'created_at' => now(),
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $product->price,
            'cost' => $product->cost,
            'total' => $product->price,
        ]);
    }

    livewire(ProductsSalesPerformanceReport::class)
        ->assertSeeLivewire(OrderTypePerformanceWidget::class)
        ->assertSeeLivewire(ProductsSalesTableWidget::class);
});

// Product Type Filtering Tests
it('excludes raw material products from report', function () {
    // Create raw material product
    $rawMaterial = Product::factory()->create([
        'price' => 50,
        'cost' => 30,
        'type' => ProductType::RawMaterial,
    ]);

    // Create consumable product
    $consumable = Product::factory()->create([
        'price' => 100,
        'cost' => 50,
        'type' => ProductType::Consumable,
    ]);

    // Create orders for both products
    $order = Order::factory()->create([
        'type' => OrderType::DINE_IN,
        'status' => OrderStatus::COMPLETED,
        'created_at' => now(),
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $rawMaterial->id,
        'quantity' => 5,
        'price' => $rawMaterial->price,
        'cost' => $rawMaterial->cost,
        'total' => $rawMaterial->price * 5,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $consumable->id,
        'quantity' => 2,
        'price' => $consumable->price,
        'cost' => $consumable->cost,
        'total' => $consumable->price * 2,
    ]);

    // Should show widgets because we have consumable products
    livewire(ProductsSalesPerformanceReport::class)
        ->assertSeeLivewire(ProductsSalesStatsWidget::class);
});

// Category Performance Tests
it('includes category performance widget when products have categories', function () {
    $category1 = Category::factory()->create(['name' => 'مشروبات']);
    $category2 = Category::factory()->create(['name' => 'وجبات رئيسية']);

    $product1 = Product::factory()->create([
        'category_id' => $category1->id,
        'price' => 50,
        'cost' => 20,
        'type' => ProductType::Consumable,
    ]);

    $product2 = Product::factory()->create([
        'category_id' => $category2->id,
        'price' => 150,
        'cost' => 70,
        'type' => ProductType::Consumable,
    ]);

    $order = Order::factory()->create([
        'type' => OrderType::DINE_IN,
        'status' => OrderStatus::COMPLETED,
        'created_at' => now(),
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product1->id,
        'quantity' => 3,
        'price' => $product1->price,
        'cost' => $product1->cost,
        'total' => $product1->price * 3,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product2->id,
        'quantity' => 2,
        'price' => $product2->price,
        'cost' => $product2->cost,
        'total' => $product2->price * 2,
    ]);

    livewire(ProductsSalesPerformanceReport::class)
        ->assertSeeLivewire(CategoryPerformanceWidget::class);
});

// Navigation Tests
it('has correct page title', function () {
    $page = livewire(ProductsSalesPerformanceReport::class);

    expect($page->instance()->getTitle())
        ->toBe('تقرير أداء المنتجات في المبيعات');
});
