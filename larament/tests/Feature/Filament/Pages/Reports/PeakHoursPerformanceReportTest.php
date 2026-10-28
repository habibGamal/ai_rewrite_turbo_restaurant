<?php

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Reports\PeakHoursPerformanceReport;
use App\Filament\Widgets\DailyPerformanceChartWidget;
use App\Filament\Widgets\HourlyPerformanceChartWidget;
use App\Filament\Widgets\HourlyPerformanceTableWidget;
use App\Filament\Widgets\NoPeakHoursSalesInPeriodWidget;
use App\Filament\Widgets\PeakHoursStatsWidget;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    // Create admin user and authenticate
    $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    actingAs($this->admin);

    // Create a shift for orders
    $this->shift = Shift::factory()->create();
});

// Page Rendering Tests
it('can render the peak hours performance report page', function () {
    livewire(PeakHoursPerformanceReport::class)
        ->assertSuccessful();
});

it('has correct page title', function () {
    livewire(PeakHoursPerformanceReport::class)
        ->assertSee('تقرير أداء ساعات الذروة والأنماط الزمنية');
});

// Filters Form Tests
it('can set filters with preset period', function () {
    livewire(PeakHoursPerformanceReport::class)
        ->fillForm([
            'presetPeriod' => 'last_7_days',
        ])
        ->assertHasNoFormErrors();
});

it('can set filters with custom date range', function () {
    $startDate = now()->subDays(10)->toDateString();
    $endDate = now()->toDateString();

    livewire(PeakHoursPerformanceReport::class)
        ->fillForm([
            'presetPeriod' => 'custom',
            'startDate' => $startDate,
            'endDate' => $endDate,
        ])
        ->assertHasNoFormErrors();
});

// Widget Display Tests - With Orders
it('shows correct widgets when orders exist', function () {
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);

    $orders = Order::factory()
        ->count(5)
        ->create([
            'status' => OrderStatus::COMPLETED,
            'shift_id' => $this->shift->id,
            'total' => 200,
            'profit' => 100,
            'created_at' => now()->subDays(5),
        ]);

    foreach ($orders as $order) {
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 100,
            'cost' => 50,
            'total' => 200,
        ]);
    }

    $component = livewire(PeakHoursPerformanceReport::class);
    $component->assertSuccessful();

    $widgets = $component->instance()->getWidgets();
    expect($widgets)->toContain(PeakHoursStatsWidget::class)
        ->and($widgets)->toContain(HourlyPerformanceChartWidget::class)
        ->and($widgets)->toContain(DailyPerformanceChartWidget::class)
        ->and($widgets)->toContain(HourlyPerformanceTableWidget::class);
});

// Widget Display Tests - No Orders
it('shows no sales widget when no orders exist in period', function () {
    livewire(PeakHoursPerformanceReport::class)
        ->assertSuccessful()
        ->assertSeeLivewire(NoPeakHoursSalesInPeriodWidget::class);
});

it('shows no sales widget when no completed orders exist', function () {
    // Create orders with different status (not completed)
    Order::factory()
        ->count(3)
        ->create([
            'status' => OrderStatus::PROCESSING,
            'shift_id' => $this->shift->id,
            'created_at' => now()->subDays(5),
        ]);

    livewire(PeakHoursPerformanceReport::class)
        ->assertSuccessful()
        ->assertSeeLivewire(NoPeakHoursSalesInPeriodWidget::class);
});

// Date Range Filtering Tests
it('filters data by date range correctly', function () {
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);

    // Create orders within range
    $orderInRange = Order::factory()->create([
        'status' => OrderStatus::COMPLETED,
        'shift_id' => $this->shift->id,
        'total' => 100,
        'profit' => 50,
        'created_at' => now()->subDays(5),
    ]);

    OrderItem::factory()->create([
        'order_id' => $orderInRange->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 100,
        'cost' => 50,
        'total' => 100,
    ]);

    // Create orders outside range
    $orderOutsideRange = Order::factory()->create([
        'status' => OrderStatus::COMPLETED,
        'shift_id' => $this->shift->id,
        'total' => 100,
        'profit' => 50,
        'created_at' => now()->subDays(60),
    ]);

    OrderItem::factory()->create([
        'order_id' => $orderOutsideRange->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 100,
        'cost' => 50,
        'total' => 100,
    ]);

    $startDate = now()->subDays(7)->toDateString();
    $endDate = now()->toDateString();

    livewire(PeakHoursPerformanceReport::class)
        ->fillForm([
            'presetPeriod' => 'custom',
            'startDate' => $startDate,
            'endDate' => $endDate,
        ])
        ->assertSuccessful();
});

// Preset Period Tests
it('applies last 7 days preset correctly', function () {
    livewire(PeakHoursPerformanceReport::class)
        ->fillForm([
            'presetPeriod' => 'last_7_days',
        ])
        ->assertSuccessful();
});

it('applies last 30 days preset correctly', function () {
    livewire(PeakHoursPerformanceReport::class)
        ->fillForm([
            'presetPeriod' => 'last_30_days',
        ])
        ->assertSuccessful();
});

it('applies today preset correctly', function () {
    livewire(PeakHoursPerformanceReport::class)
        ->fillForm([
            'presetPeriod' => 'today',
        ])
        ->assertSuccessful();
});

it('applies this week preset correctly', function () {
    livewire(PeakHoursPerformanceReport::class)
        ->fillForm([
            'presetPeriod' => 'this_week',
        ])
        ->assertSuccessful();
});

it('applies this month preset correctly', function () {
    livewire(PeakHoursPerformanceReport::class)
        ->fillForm([
            'presetPeriod' => 'this_month',
        ])
        ->assertSuccessful();
});

// Access Control Tests
it('allows viewer access', function () {
    $viewer = User::factory()->create(['role' => UserRole::VIEWER]);
    actingAs($viewer);

    livewire(PeakHoursPerformanceReport::class)
        ->assertSuccessful();
});

it('denies cashier access', function () {
    $cashier = User::factory()->create(['role' => UserRole::CASHIER]);
    actingAs($cashier);

    livewire(PeakHoursPerformanceReport::class)
        ->assertForbidden();
});

// Service Integration Tests
it('integrates with peak hours report service', function () {
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);

    // Create orders at different hours
    $hours = [8, 12, 18, 20];
    foreach ($hours as $hour) {
        $order = Order::factory()->create([
            'status' => OrderStatus::COMPLETED,
            'shift_id' => $this->shift->id,
            'total' => 100,
            'profit' => 50,
            'created_at' => now()->setHour($hour)->subDays(2),
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

    livewire(PeakHoursPerformanceReport::class)
        ->assertSuccessful();
});

// Multiple Orders Scenario Tests
it('displays widgets with multiple orders at peak hours', function () {
    $product = Product::factory()->create(['price' => 150, 'cost' => 75]);

    // Create many orders at peak hours (12-14, 18-20)
    $peakHours = [12, 13, 14, 18, 19, 20];

    foreach ($peakHours as $hour) {
        for ($i = 0; $i < 3; $i++) {
            $qty = rand(1, 3);
            $order = Order::factory()->create([
                'status' => OrderStatus::COMPLETED,
                'shift_id' => $this->shift->id,
                'total' => 150 * $qty,
                'profit' => 75 * $qty,
                'created_at' => now()->setHour($hour)->setMinute(rand(0, 59))->subDays(rand(1, 7)),
            ]);

            OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'quantity' => $qty,
                'price' => 150,
                'cost' => 75,
                'total' => 150 * $qty,
            ]);
        }
    }

    livewire(PeakHoursPerformanceReport::class)
        ->assertSuccessful();
});

// Edge Cases
it('handles empty date range gracefully', function () {
    livewire(PeakHoursPerformanceReport::class)
        ->fillForm([
            'presetPeriod' => 'custom',
            'startDate' => null,
            'endDate' => null,
        ])
        ->assertSuccessful();
});

it('handles orders with different days of week', function () {
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);

    // Create orders on different days of the week
    for ($i = 0; $i < 7; $i++) {
        $order = Order::factory()->create([
            'status' => OrderStatus::COMPLETED,
            'shift_id' => $this->shift->id,
            'total' => 200,
            'profit' => 100,
            'created_at' => now()->subDays($i)->setHour(12),
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 100,
            'cost' => 50,
            'total' => 200,
        ]);
    }

    livewire(PeakHoursPerformanceReport::class)
        ->assertSuccessful();
});

it('handles single order scenario', function () {
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);

    $order = Order::factory()->create([
        'status' => OrderStatus::COMPLETED,
        'shift_id' => $this->shift->id,
        'total' => 100,
        'profit' => 50,
        'created_at' => now()->subDays(1),
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 100,
        'cost' => 50,
        'total' => 100,
    ]);

    livewire(PeakHoursPerformanceReport::class)
        ->assertSuccessful();
});

// Default Filter State Tests
it('has default filter values', function () {
    $component = livewire(PeakHoursPerformanceReport::class);

    $component->assertSuccessful();

    $expectedStartDate = now()->subDays(29)->startOfDay()->toDateString();
    $expectedEndDate = now()->endOfDay()->toDateString();

    expect($component->instance()->filters['startDate'] ?? null)
        ->toBe($expectedStartDate);
    expect($component->instance()->filters['endDate'] ?? null)
        ->toBe($expectedEndDate);
});

// Navigation Tests
it('is in the reports navigation group', function () {
    expect(PeakHoursPerformanceReport::getNavigationGroup())
        ->toBe('التقارير');
});

it('has correct navigation label', function () {
    expect(PeakHoursPerformanceReport::getNavigationLabel())
        ->toBe('تقرير أداء ساعات الذروة');
});

it('has correct navigation sort order', function () {
    expect(PeakHoursPerformanceReport::getNavigationSort())
        ->toBe(7);
});

it('has correct route path', function () {
    $reflection = new ReflectionClass(PeakHoursPerformanceReport::class);
    $property = $reflection->getProperty('routePath');
    $property->setAccessible(true);

    expect($property->getValue())
        ->toBe('peak-hours-performance-report');
});

// Widget Isolated Rendering Tests
it('renders individual peak hours widgets directly without error', function () {
    Order::factory()->create([
        'status' => OrderStatus::COMPLETED,
        'shift_id' => $this->shift->id,
        'total' => 300,
        'profit' => 120,
        'created_at' => now()->subDays(2)->setHour(14),
    ]);

    livewire(PeakHoursStatsWidget::class)
        ->assertSuccessful();

    livewire(HourlyPerformanceChartWidget::class)
        ->assertSuccessful();

    livewire(DailyPerformanceChartWidget::class)
        ->assertSuccessful();

    livewire(HourlyPerformanceTableWidget::class)
        ->assertSuccessful();
});
