<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\UserRole;
use App\Filament\Pages\Reports\ChannelPerformanceReport;
use App\Filament\Widgets\ChannelMarketShareWidget;
use App\Filament\Widgets\ChannelPerformanceStatsWidget;
use App\Filament\Widgets\NoCustomersSalesInPeriodWidget;
use App\Models\Customer;
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
it('can render the channel performance report page', function () {
    livewire(ChannelPerformanceReport::class)
        ->assertSuccessful();
});

it('can access page with viewer role', function () {
    $viewer = User::factory()->create(['role' => UserRole::VIEWER]);
    actingAs($viewer);

    livewire(ChannelPerformanceReport::class)
        ->assertSuccessful();
});

it('cannot access page with cashier role', function () {
    $cashier = User::factory()->create(['role' => UserRole::CASHIER]);
    actingAs($cashier);

    livewire(ChannelPerformanceReport::class)
        ->assertForbidden();
});

// Widget Rendering Tests - No Data Scenario
it('shows no sales widget when there are no completed orders', function () {
    $component = livewire(ChannelPerformanceReport::class);

    $widgets = $component->instance()->getWidgets();

    expect($widgets)->toContain(NoCustomersSalesInPeriodWidget::class);
});

it('does not show performance widgets when there are no completed orders', function () {
    $component = livewire(ChannelPerformanceReport::class);

    $widgets = $component->instance()->getWidgets();

    expect($widgets)->not->toContain(ChannelPerformanceStatsWidget::class);
    expect($widgets)->not->toContain(ChannelMarketShareWidget::class);
});

// Widget Rendering Tests - With Data
it('shows performance widgets when there are completed orders', function () {
    // Create test data
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);
    $customer = Customer::factory()->create();

    // Create orders with different types (channels)
    $deliveryOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'type' => OrderType::DELIVERY,
        'status' => OrderStatus::COMPLETED,
        'created_at' => now()->subDays(5),
    ]);

    OrderItem::factory()->create([
        'order_id' => $deliveryOrder->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'price' => $product->price,
        'cost' => $product->cost,
        'total' => $product->price * 2,
    ]);

    $dineInOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'type' => OrderType::DINE_IN,
        'status' => OrderStatus::COMPLETED,
        'created_at' => now()->subDays(3),
    ]);

    OrderItem::factory()->create([
        'order_id' => $dineInOrder->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'price' => $product->price,
        'cost' => $product->cost,
        'total' => $product->price * 2,
    ]);

    livewire(ChannelPerformanceReport::class)
        ->assertSeeLivewire(ChannelPerformanceStatsWidget::class)
        ->assertSeeLivewire(ChannelMarketShareWidget::class)
        ->assertDontSeeLivewire(NoCustomersSalesInPeriodWidget::class);
});

it('does not count pending orders in the report', function () {
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);
    $customer = Customer::factory()->create();

    // Create pending order
    $pendingOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'type' => OrderType::DELIVERY,
        'status' => OrderStatus::PENDING,
        'created_at' => now()->subDays(5),
    ]);

    OrderItem::factory()->create([
        'order_id' => $pendingOrder->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'price' => $product->price,
        'cost' => $product->cost,
        'total' => $product->price * 2,
    ]);

    $component = livewire(ChannelPerformanceReport::class);
    $widgets = $component->instance()->getWidgets();

    expect($widgets)->toContain(NoCustomersSalesInPeriodWidget::class);
    expect($widgets)->not->toContain(ChannelPerformanceStatsWidget::class);
});

it('does not count cancelled orders in the report', function () {
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);
    $customer = Customer::factory()->create();

    // Create cancelled order
    $cancelledOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'type' => OrderType::DELIVERY,
        'status' => OrderStatus::CANCELLED,
        'created_at' => now()->subDays(5),
    ]);

    OrderItem::factory()->create([
        'order_id' => $cancelledOrder->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'price' => $product->price,
        'cost' => $product->cost,
        'total' => $product->price * 2,
    ]);

    $component = livewire(ChannelPerformanceReport::class);
    $widgets = $component->instance()->getWidgets();

    expect($widgets)->toContain(NoCustomersSalesInPeriodWidget::class);
    expect($widgets)->not->toContain(ChannelPerformanceStatsWidget::class);
});

// Channel Performance Tests
it('tracks performance for multiple channels', function () {
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);
    $customer = Customer::factory()->create();

    // Create orders for different channels
    $channels = [
        OrderType::DELIVERY,
        OrderType::DINE_IN,
        OrderType::TAKEAWAY,
        OrderType::WEB_DELIVERY,
    ];

    foreach ($channels as $channel) {
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'type' => $channel,
            'status' => OrderStatus::COMPLETED,
            'created_at' => now()->subDays(rand(1, 7)),
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => rand(1, 3),
            'price' => $product->price,
            'cost' => $product->cost,
            'total' => $product->price * rand(1, 3),
        ]);
    }

    $component = livewire(ChannelPerformanceReport::class);
    $widgets = $component->instance()->getWidgets();

    expect($widgets)->toContain(ChannelPerformanceStatsWidget::class);
    expect($widgets)->toContain(ChannelMarketShareWidget::class);
});

it('shows widgets with multiple orders in same channel', function () {
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);
    $customer1 = Customer::factory()->create();
    $customer2 = Customer::factory()->create();

    // Create multiple orders in same channel
    foreach ([$customer1, $customer2] as $customer) {
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'type' => OrderType::DELIVERY,
            'status' => OrderStatus::COMPLETED,
            'created_at' => now()->subDays(rand(1, 7)),
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => $product->price,
            'cost' => $product->cost,
            'total' => $product->price * 2,
        ]);
    }

    $component = livewire(ChannelPerformanceReport::class);
    $widgets = $component->instance()->getWidgets();

    expect($widgets)->toContain(ChannelPerformanceStatsWidget::class);
    expect($widgets)->toContain(ChannelMarketShareWidget::class);
});

// Service Integration Tests
it('handles empty results from service gracefully', function () {
    // No orders created, service will return empty results
    $component = livewire(ChannelPerformanceReport::class);
    $widgets = $component->instance()->getWidgets();

    expect($widgets)->toContain(NoCustomersSalesInPeriodWidget::class);
    expect($widgets)->not->toContain(ChannelPerformanceStatsWidget::class);
});

// Edge Cases
it('handles single order correctly', function () {
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);
    $customer = Customer::factory()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'type' => OrderType::DELIVERY,
        'status' => OrderStatus::COMPLETED,
        'created_at' => now()->subDays(5),
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => $product->price,
        'cost' => $product->cost,
        'total' => $product->price,
    ]);

    $component = livewire(ChannelPerformanceReport::class);
    $widgets = $component->instance()->getWidgets();

    expect($widgets)->toContain(ChannelPerformanceStatsWidget::class);
    expect($widgets)->toContain(ChannelMarketShareWidget::class);
});

it('handles orders with multiple items', function () {
    $product1 = Product::factory()->create(['price' => 100, 'cost' => 50]);
    $product2 = Product::factory()->create(['price' => 50, 'cost' => 25]);
    $customer = Customer::factory()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'type' => OrderType::DELIVERY,
        'status' => OrderStatus::COMPLETED,
        'created_at' => now()->subDays(5),
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product1->id,
        'quantity' => 2,
        'price' => $product1->price,
        'cost' => $product1->cost,
        'total' => $product1->price * 2,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product2->id,
        'quantity' => 3,
        'price' => $product2->price,
        'cost' => $product2->cost,
        'total' => $product2->price * 3,
    ]);

    livewire(ChannelPerformanceReport::class)
        ->assertSeeLivewire(ChannelPerformanceStatsWidget::class)
        ->assertSeeLivewire(ChannelMarketShareWidget::class);
});
