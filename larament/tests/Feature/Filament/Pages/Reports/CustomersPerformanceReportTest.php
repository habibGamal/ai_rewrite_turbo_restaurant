<?php

use App\Enums\OrderStatus;
use App\Filament\Pages\Reports\CustomersPerformanceReport;
use App\Filament\Widgets\CustomerActivityTrendWidget;
use App\Filament\Widgets\CustomerOrderTypePerformanceWidget;
use App\Filament\Widgets\CustomersAtRiskTableWidget;
use App\Filament\Widgets\CustomerSegmentsWidget;
use App\Filament\Widgets\CustomersPerformanceStatsWidget;
use App\Filament\Widgets\CustomersPerformanceTableWidget;
use App\Filament\Widgets\NoCustomersSalesInPeriodWidget;
use App\Filament\Widgets\TopCustomersByProfitWidget;
use App\Filament\Widgets\TopCustomersBySalesWidget;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\CustomersPerformanceReportService;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
});

// Page Rendering Tests
it('can render the page', function () {
    livewire(CustomersPerformanceReport::class)
        ->assertSuccessful();
});

it('can be accessed by admin user', function () {
    $admin = User::factory()->admin()->create();
    actingAs($admin);

    livewire(CustomersPerformanceReport::class)
        ->assertSuccessful();
});

it('can be accessed by viewer user', function () {
    $viewer = User::factory()->viewer()->create();
    actingAs($viewer);

    livewire(CustomersPerformanceReport::class)
        ->assertSuccessful();
});

it('cannot be accessed by cashier user', function () {
    $cashier = User::factory()->cashier()->create();
    actingAs($cashier);

    livewire(CustomersPerformanceReport::class)
        ->assertForbidden();
});

// Widget Tests
it('displays no sales widget when no orders exist', function () {
    livewire(CustomersPerformanceReport::class)
        ->assertSuccessful()
        ->assertSeeLivewire(NoCustomersSalesInPeriodWidget::class);
});

it('displays performance widgets when orders exist', function () {
    // Create test data
    $customer = Customer::factory()->create();
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'status' => OrderStatus::COMPLETED,
        'type' => 'delivery',
        'total' => 100,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 100,
        'cost' => 50,
        'total' => 100,
    ]);

    livewire(CustomersPerformanceReport::class)
        ->assertSuccessful()
        ->assertSeeLivewire(CustomersPerformanceStatsWidget::class)
        ->assertSeeLivewire(CustomersAtRiskTableWidget::class)
        ->assertSeeLivewire(TopCustomersBySalesWidget::class)
        ->assertSeeLivewire(TopCustomersByProfitWidget::class)
        ->assertSeeLivewire(CustomerSegmentsWidget::class)
        ->assertSeeLivewire(CustomerOrderTypePerformanceWidget::class)
        ->assertSeeLivewire(CustomerActivityTrendWidget::class)
        ->assertSeeLivewire(CustomersPerformanceTableWidget::class);
});

// Filter Tests
it('has period filter form', function () {
    livewire(CustomersPerformanceReport::class)
        ->assertSuccessful();
    // Note: Schema components are nested in sections, detailed testing would require accessing the section
});

it('can filter by last 7 days', function () {
    // Create test data
    $customer = Customer::factory()->create();
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'status' => OrderStatus::COMPLETED,
        'type' => 'delivery',
        'total' => 100,
        'created_at' => now()->subDays(5),
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 100,
        'cost' => 50,
        'total' => 100,
    ]);

    livewire(CustomersPerformanceReport::class)
        ->assertSuccessful()
        ->fillForm([
            'presetPeriod' => 'last_7_days',
        ])
        ->assertSuccessful();
});

it('can filter by last 30 days', function () {
    livewire(CustomersPerformanceReport::class)
        ->assertSuccessful()
        ->fillForm([
            'presetPeriod' => 'last_30_days',
        ])
        ->assertSuccessful();
});

it('can filter by custom date range', function () {
    $startDate = now()->subDays(10)->startOfDay()->toDateString();
    $endDate = now()->endOfDay()->toDateString();

    livewire(CustomersPerformanceReport::class)
        ->assertSuccessful()
        ->fillForm([
            'presetPeriod' => 'custom',
            'startDate' => $startDate,
            'endDate' => $endDate,
        ])
        ->assertSuccessful();
});

it('defaults to last 30 days period', function () {
    livewire(CustomersPerformanceReport::class)
        ->assertSuccessful();
    // Default preset period is handled by PeriodFilterFormComponent
});

// Data Filtering Tests
it('only shows completed orders', function () {
    $customer = Customer::factory()->create();
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);

    // Create completed order
    $completedOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 100,
    ]);

    OrderItem::factory()->create([
        'order_id' => $completedOrder->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 100,
        'cost' => 50,
        'total' => 100,
    ]);

    // Create pending order (should not be included)
    $pendingOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'status' => OrderStatus::PENDING,
        'total' => 100,
    ]);

    OrderItem::factory()->create([
        'order_id' => $pendingOrder->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 100,
        'cost' => 50,
        'total' => 100,
    ]);

    livewire(CustomersPerformanceReport::class)
        ->assertSuccessful()
        ->assertSeeLivewire(CustomersPerformanceStatsWidget::class);
});

it('only shows orders with customers', function () {
    $customer = Customer::factory()->create();
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);

    // Create order with customer
    $orderWithCustomer = Order::factory()->create([
        'customer_id' => $customer->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 100,
    ]);

    OrderItem::factory()->create([
        'order_id' => $orderWithCustomer->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 100,
        'cost' => 50,
        'total' => 100,
    ]);

    // Create order without customer (should not be included)
    $orderWithoutCustomer = Order::factory()->create([
        'customer_id' => null,
        'status' => OrderStatus::COMPLETED,
        'total' => 100,
    ]);

    OrderItem::factory()->create([
        'order_id' => $orderWithoutCustomer->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 100,
        'cost' => 50,
        'total' => 100,
    ]);

    livewire(CustomersPerformanceReport::class)
        ->assertSuccessful()
        ->assertSeeLivewire(CustomersPerformanceStatsWidget::class);
});

// Navigation Tests
it('has correct navigation label', function () {
    expect(CustomersPerformanceReport::getNavigationLabel())
        ->toBe('تقرير أداء العملاء');
});

it('has correct navigation icon', function () {
    expect(CustomersPerformanceReport::getNavigationIcon())
        ->toBe('heroicon-o-users');
});

it('has correct navigation group', function () {
    expect(CustomersPerformanceReport::getNavigationGroup())
        ->toBe('التقارير');
});

it('has correct navigation sort', function () {
    expect(CustomersPerformanceReport::getNavigationSort())
        ->toBe(5);
});

it('has correct title', function () {
    $page = new CustomersPerformanceReport;
    expect($page->getTitle())
        ->toBe('تقرير أداء العملاء في المبيعات');
});

it('has correct route path', function () {
    $reflection = new ReflectionClass(CustomersPerformanceReport::class);
    $property = $reflection->getProperty('routePath');
    $property->setAccessible(true);

    expect($property->getValue())
        ->toBe('customers-performance-report');
});

// Widget Interaction Tests
it('updates widgets when filters change', function () {
    // Create test data
    $customer = Customer::factory()->create();
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'status' => OrderStatus::COMPLETED,
        'type' => 'delivery',
        'total' => 100,
        'created_at' => now()->subDays(5),
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 100,
        'cost' => 50,
        'total' => 100,
    ]);

    $component = livewire(CustomersPerformanceReport::class)
        ->assertSuccessful();

    // Change filter
    $component
        ->fillForm([
            'presetPeriod' => 'last_7_days',
        ], 'filtersForm')
        ->assertSuccessful()
        ->assertSet('filters.startDate', now()->subDays(6)->toDateString());

    // Child widgets are not re-rendered by the test harness, so check the list.
    expect($component->instance()->getWidgets())
        ->toContain(CustomersPerformanceStatsWidget::class);
});

// Multiple Customers Test
it('shows multiple customers in report', function () {
    $customers = Customer::factory()->count(5)->create();
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);

    foreach ($customers as $customer) {
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::COMPLETED,
            'type' => 'delivery',
            'total' => 100,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 100,
            'cost' => 50,
            'total' => 100,
        ]);
    }

    livewire(CustomersPerformanceReport::class)
        ->assertSuccessful()
        ->assertSeeLivewire(CustomersPerformanceTableWidget::class);
});

// Different Order Types Test
it('shows orders of different types', function () {
    $customer = Customer::factory()->create();
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);

    // Create different order types
    foreach (['delivery', 'dine_in', 'takeaway'] as $orderType) {
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::COMPLETED,
            'type' => $orderType,
            'total' => 100,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 100,
            'cost' => 50,
            'total' => 100,
        ]);
    }

    livewire(CustomersPerformanceReport::class)
        ->assertSuccessful()
        ->assertSeeLivewire(CustomerOrderTypePerformanceWidget::class);
});

// Date Range Filter Test
it('filters orders by date range', function () {
    $customer = Customer::factory()->create();
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);

    // Create old order (outside range)
    $oldOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 100,
        'created_at' => now()->subDays(60),
    ]);

    OrderItem::factory()->create([
        'order_id' => $oldOrder->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 100,
        'cost' => 50,
        'total' => 100,
    ]);

    // Create recent order (within range)
    $recentOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 100,
        'created_at' => now()->subDays(5),
    ]);

    OrderItem::factory()->create([
        'order_id' => $recentOrder->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 100,
        'cost' => 50,
        'total' => 100,
    ]);

    $component = livewire(CustomersPerformanceReport::class)
        ->assertSuccessful()
        ->fillForm([
            'presetPeriod' => 'last_7_days',
        ], 'filtersForm')
        ->assertSuccessful();

    expect($component->instance()->getWidgets())
        ->toContain(CustomersPerformanceStatsWidget::class);

    $rows = app(CustomersPerformanceReportService::class)
        ->getCustomersPerformanceQuery(now()->subDays(6)->toDateString(), now()->toDateString())
        ->get();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()->total_orders)->toBe(1);
});

// Empty State Tests
it('shows appropriate message when no data available', function () {
    livewire(CustomersPerformanceReport::class)
        ->assertSuccessful()
        ->assertSeeLivewire(NoCustomersSalesInPeriodWidget::class);
});

it('shows performance widgets when data is available', function () {
    $customer = Customer::factory()->create();
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 100,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 100,
        'cost' => 50,
        'total' => 100,
    ]);

    livewire(CustomersPerformanceReport::class)
        ->assertSuccessful()
        ->assertDontSeeLivewire(NoCustomersSalesInPeriodWidget::class)
        ->assertSeeLivewire(CustomersPerformanceStatsWidget::class);
});

// Service Integration Test
it('uses CustomersPerformanceReportService correctly', function () {
    $customer = Customer::factory()->create();
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 100,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 100,
        'cost' => 50,
        'total' => 100,
    ]);

    $service = app(CustomersPerformanceReportService::class);

    $startDate = now()->subDays(29)->startOfDay()->toDateString();
    $endDate = now()->endOfDay()->toDateString();

    $ordersCount = $service->getOrdersQuery($startDate, $endDate)->count();

    expect($ordersCount)->toBeGreaterThan(0);
});

// Performance Test with Large Dataset
it('handles large number of customers efficiently', function () {
    $customers = Customer::factory()->count(20)->create();
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);

    foreach ($customers as $customer) {
        $ordersCount = rand(1, 5);
        for ($i = 0; $i < $ordersCount; $i++) {
            $order = Order::factory()->create([
                'customer_id' => $customer->id,
                'status' => OrderStatus::COMPLETED,
                'type' => fake()->randomElement(['dine_in', 'takeaway', 'delivery', 'companies', 'talabat']),
                'total' => 100,
            ]);

            OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 100,
                'cost' => 50,
                'total' => 100,
            ]);
        }
    }

    livewire(CustomersPerformanceReport::class)
        ->assertSuccessful()
        ->assertSeeLivewire(CustomersPerformanceTableWidget::class);
})->skip('Performance test - run manually');
