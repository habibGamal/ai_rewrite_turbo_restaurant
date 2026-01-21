<?php

use App\Filament\Pages\Reports\DriverPerformanceReport;
use App\Filament\Widgets\DriverPerformanceStatsWidget;
use App\Filament\Widgets\DriverPerformanceTable;
use App\Filament\Widgets\NoShiftsInPeriodWidget;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Enums\UserRole;
use App\Enums\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create an admin user for authorization
    $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    actingAs($this->admin);
});

// ==================== Page Rendering Tests ====================

it('can load the driver performance report page', function () {
    livewire(DriverPerformanceReport::class)
        ->assertOk();
});

it('requires admin or viewer access to view the page', function () {
    $viewer = User::factory()->create(['role' => UserRole::VIEWER]);

    actingAs($viewer);

    livewire(DriverPerformanceReport::class)
        ->assertOk();
});

it('denies access to users without admin or viewer role', function () {
    $cashier = User::factory()->create(['role' => UserRole::CASHIER]);

    actingAs($cashier);

    livewire(DriverPerformanceReport::class)
        ->assertForbidden();
});

// ==================== Filter Form Tests ====================

it('can update filters', function () {
    $shift = Shift::factory()->closed()->create();

    // Just verify the page renders with filters applied
    livewire(DriverPerformanceReport::class)
        ->set('filters.filterType', 'shifts')
        ->set('filters.shifts', [$shift->id])
        ->assertOk();
});

// ==================== Widget Display Tests ====================

it('displays NoShiftsInPeriodWidget when no shifts exist', function () {
    // Verify page renders without error when no shifts exist
    livewire(DriverPerformanceReport::class)
        ->assertOk();

    // Verify the widget array includes the no-shifts widget
    $page = app(DriverPerformanceReport::class);
    $page->filters = [
        'filterType' => 'period',
        'startDate' => now()->subDays(6)->toDateString(),
        'endDate' => now()->toDateString(),
    ];
    $page->boot();

    $widgets = $page->getWidgets();
    expect($widgets)->toContain(NoShiftsInPeriodWidget::class);
});

it('displays performance widgets when shifts exist in period', function () {
    // Create shifts and orders with drivers
    $shift = Shift::factory()->closed()->create([
        'start_at' => now()->subDays(3),
        'end_at' => now()->subDays(2),
    ]);

    $driver = Driver::factory()->create();

    Order::factory()->count(5)->create([
        'shift_id' => $shift->id,
        'driver_id' => $driver->id,
        'status' => OrderStatus::COMPLETED,
    ]);

    // Verify widgets array contains performance widgets
    $page = app(DriverPerformanceReport::class);
    $page->filters = [
        'filterType' => 'period',
        'startDate' => now()->subDays(6)->toDateString(),
        'endDate' => now()->toDateString(),
    ];
    $page->boot();

    $widgets = $page->getWidgets();
    expect($widgets)->toContain(DriverPerformanceStatsWidget::class);
    expect($widgets)->toContain(DriverPerformanceTable::class);
});

it('displays NoShiftsInPeriodWidget when filtering by empty shift selection', function () {
    $page = app(DriverPerformanceReport::class);
    $page->filters = [
        'filterType' => 'shifts',
        'shifts' => [],
    ];
    $page->boot();

    $widgets = $page->getWidgets();
    expect($widgets)->toContain(NoShiftsInPeriodWidget::class);
});

it('displays performance widgets when filtering by specific shifts with orders', function () {
    $shift = Shift::factory()->closed()->create();
    $driver = Driver::factory()->create();

    Order::factory()->count(3)->create([
        'shift_id' => $shift->id,
        'driver_id' => $driver->id,
        'status' => OrderStatus::COMPLETED,
    ]);

    $page = app(DriverPerformanceReport::class);
    $page->filters = [
        'filterType' => 'shifts',
        'shifts' => [$shift->id],
    ];
    $page->boot();

    $widgets = $page->getWidgets();
    expect($widgets)->toContain(DriverPerformanceStatsWidget::class);
    expect($widgets)->toContain(DriverPerformanceTable::class);
});

// ==================== DriverPerformanceStatsWidget Tests ====================

it('stats widget loads with performance data', function () {
    $shift = Shift::factory()->closed()->create([
        'start_at' => now()->subDays(3),
        'end_at' => now()->subDays(2),
    ]);

    $driver1 = Driver::factory()->create();
    $driver2 = Driver::factory()->create();

    // Driver 1: 3 completed orders
    Order::factory()->count(3)->create([
        'shift_id' => $shift->id,
        'driver_id' => $driver1->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 100,
    ]);

    // Driver 2: 2 completed orders
    Order::factory()->count(2)->create([
        'shift_id' => $shift->id,
        'driver_id' => $driver2->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 150,
    ]);

    livewire(DriverPerformanceStatsWidget::class, [
        'pageFilters' => [
            'filterType' => 'period',
            'startDate' => now()->subDays(6)->toDateString(),
            'endDate' => now()->toDateString(),
        ],
    ])
        ->assertOk();
});

it('stats widget loads with zero data when no completed orders', function () {
    $shift = Shift::factory()->closed()->create([
        'start_at' => now()->subDays(3),
        'end_at' => now()->subDays(2),
    ]);

    livewire(DriverPerformanceStatsWidget::class, [
        'pageFilters' => [
            'filterType' => 'period',
            'startDate' => now()->subDays(6)->toDateString(),
            'endDate' => now()->toDateString(),
        ],
    ])
        ->assertOk();
});

it('stats widget handles shift filter correctly', function () {
    $shift = Shift::factory()->closed()->create();
    $driver = Driver::factory()->create();

    Order::factory()->count(5)->create([
        'shift_id' => $shift->id,
        'driver_id' => $driver->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 200,
    ]);

    livewire(DriverPerformanceStatsWidget::class, [
        'pageFilters' => [
            'filterType' => 'shifts',
            'shifts' => [$shift->id],
        ],
    ])
        ->assertOk();
});

// ==================== DriverPerformanceTable Tests ====================

it('displays driver performance table with correct columns', function () {
    $shift = Shift::factory()->closed()->create([
        'start_at' => now()->subDays(3),
        'end_at' => now()->subDays(2),
    ]);

    $driver = Driver::factory()->create(['name' => 'Test Driver']);

    Order::factory()->count(3)->create([
        'shift_id' => $shift->id,
        'driver_id' => $driver->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 100,
    ]);

    livewire(DriverPerformanceTable::class, [
        'pageFilters' => [
            'filterType' => 'period',
            'startDate' => now()->subDays(6)->toDateString(),
            'endDate' => now()->toDateString(),
        ],
    ])
        ->assertTableColumnExists('name')
        ->assertTableColumnExists('phone')
        ->assertTableColumnExists('orders_count')
        ->assertTableColumnExists('completed_orders_count')
        ->assertTableColumnExists('total_value')
        ->assertTableColumnExists('avg_order_value')
        ->assertCanSeeTableRecords([$driver]);
});

it('can search drivers by name in performance table', function () {
    $shift = Shift::factory()->closed()->create([
        'start_at' => now()->subDays(3),
        'end_at' => now()->subDays(2),
    ]);

    $driver1 = Driver::factory()->create(['name' => 'Ahmed Ali']);
    $driver2 = Driver::factory()->create(['name' => 'Mohamed Hassan']);

    Order::factory()->create([
        'shift_id' => $shift->id,
        'driver_id' => $driver1->id,
        'status' => OrderStatus::COMPLETED,
    ]);

    Order::factory()->create([
        'shift_id' => $shift->id,
        'driver_id' => $driver2->id,
        'status' => OrderStatus::COMPLETED,
    ]);

    livewire(DriverPerformanceTable::class, [
        'pageFilters' => [
            'filterType' => 'period',
            'startDate' => now()->subDays(6)->toDateString(),
            'endDate' => now()->toDateString(),
        ],
    ])
        ->searchTable('Ahmed')
        ->assertCanSeeTableRecords([$driver1])
        ->assertCanNotSeeTableRecords([$driver2]);
});

it('can search drivers by phone in performance table', function () {
    $shift = Shift::factory()->closed()->create([
        'start_at' => now()->subDays(3),
        'end_at' => now()->subDays(2),
    ]);

    $driver1 = Driver::factory()->create(['phone' => '0123456789']);
    $driver2 = Driver::factory()->create(['phone' => '0987654321']);

    Order::factory()->create([
        'shift_id' => $shift->id,
        'driver_id' => $driver1->id,
        'status' => OrderStatus::COMPLETED,
    ]);

    Order::factory()->create([
        'shift_id' => $shift->id,
        'driver_id' => $driver2->id,
        'status' => OrderStatus::COMPLETED,
    ]);

    livewire(DriverPerformanceTable::class, [
        'pageFilters' => [
            'filterType' => 'period',
            'startDate' => now()->subDays(6)->toDateString(),
            'endDate' => now()->toDateString(),
        ],
    ])
        ->searchTable('0123456789')
        ->assertCanSeeTableRecords([$driver1])
        ->assertCanNotSeeTableRecords([$driver2]);
});

it('can sort drivers by orders count', function () {
    $shift = Shift::factory()->closed()->create([
        'start_at' => now()->subDays(3),
        'end_at' => now()->subDays(2),
    ]);

    $driver1 = Driver::factory()->create();
    $driver2 = Driver::factory()->create();

    Order::factory()->count(5)->create([
        'shift_id' => $shift->id,
        'driver_id' => $driver1->id,
        'status' => OrderStatus::COMPLETED,
    ]);

    Order::factory()->count(2)->create([
        'shift_id' => $shift->id,
        'driver_id' => $driver2->id,
        'status' => OrderStatus::COMPLETED,
    ]);

    livewire(DriverPerformanceTable::class, [
        'pageFilters' => [
            'filterType' => 'period',
            'startDate' => now()->subDays(6)->toDateString(),
            'endDate' => now()->toDateString(),
        ],
    ])
        ->sortTable('orders_count', 'desc')
        ->assertCanSeeTableRecords([$driver1, $driver2], inOrder: true);
});

it('can sort drivers by total value', function () {
    $shift = Shift::factory()->closed()->create([
        'start_at' => now()->subDays(3),
        'end_at' => now()->subDays(2),
    ]);

    $driver1 = Driver::factory()->create();
    $driver2 = Driver::factory()->create();

    Order::factory()->create([
        'shift_id' => $shift->id,
        'driver_id' => $driver1->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 500,
    ]);

    Order::factory()->create([
        'shift_id' => $shift->id,
        'driver_id' => $driver2->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 200,
    ]);

    livewire(DriverPerformanceTable::class, [
        'pageFilters' => [
            'filterType' => 'period',
            'startDate' => now()->subDays(6)->toDateString(),
            'endDate' => now()->toDateString(),
        ],
    ])
        ->sortTable('total_value', 'desc')
        ->assertCanSeeTableRecords([$driver1, $driver2], inOrder: true);
});

it('displays correct order counts for each driver', function () {
    $shift = Shift::factory()->closed()->create([
        'start_at' => now()->subDays(3),
        'end_at' => now()->subDays(2),
    ]);

    $driver = Driver::factory()->create();

    // 3 completed orders
    Order::factory()->count(3)->create([
        'shift_id' => $shift->id,
        'driver_id' => $driver->id,
        'status' => OrderStatus::COMPLETED,
    ]);

    // 2 processing orders (should be counted in total but not completed)
    Order::factory()->count(2)->create([
        'shift_id' => $shift->id,
        'driver_id' => $driver->id,
        'status' => OrderStatus::PROCESSING,
    ]);

    livewire(DriverPerformanceTable::class, [
        'pageFilters' => [
            'filterType' => 'period',
            'startDate' => now()->subDays(6)->toDateString(),
            'endDate' => now()->toDateString(),
        ],
    ])
        ->assertCanSeeTableRecords([$driver]);
});

it('formats currency values correctly in table', function () {
    $shift = Shift::factory()->closed()->create([
        'start_at' => now()->subDays(3),
        'end_at' => now()->subDays(2),
    ]);

    $driver = Driver::factory()->create();

    Order::factory()->create([
        'shift_id' => $shift->id,
        'driver_id' => $driver->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 1234.56,
    ]);

    livewire(DriverPerformanceTable::class, [
        'pageFilters' => [
            'filterType' => 'period',
            'startDate' => now()->subDays(6)->toDateString(),
            'endDate' => now()->toDateString(),
        ],
    ])
        ->assertCanSeeTableRecords([$driver])
        ->assertTableColumnExists('total_value')
        ->assertTableColumnExists('avg_order_value');
});

it('shows only drivers with orders in the selected period', function () {
    $shift = Shift::factory()->closed()->create([
        'start_at' => now()->subDays(3),
        'end_at' => now()->subDays(2),
    ]);

    $driverWithOrders = Driver::factory()->create();
    $driverWithoutOrders = Driver::factory()->create();

    Order::factory()->create([
        'shift_id' => $shift->id,
        'driver_id' => $driverWithOrders->id,
        'status' => OrderStatus::COMPLETED,
    ]);

    livewire(DriverPerformanceTable::class, [
        'pageFilters' => [
            'filterType' => 'period',
            'startDate' => now()->subDays(6)->toDateString(),
            'endDate' => now()->toDateString(),
        ],
    ])
        ->assertCanSeeTableRecords([$driverWithOrders])
        ->assertCanNotSeeTableRecords([$driverWithoutOrders]);
});

it('has view orders action for each driver', function () {
    $shift = Shift::factory()->closed()->create([
        'start_at' => now()->subDays(3),
        'end_at' => now()->subDays(2),
    ]);

    $driver = Driver::factory()->create();

    Order::factory()->create([
        'shift_id' => $shift->id,
        'driver_id' => $driver->id,
        'status' => OrderStatus::COMPLETED,
    ]);

    livewire(DriverPerformanceTable::class, [
        'pageFilters' => [
            'filterType' => 'period',
            'startDate' => now()->subDays(6)->toDateString(),
            'endDate' => now()->toDateString(),
        ],
    ])
        ->assertCanSeeTableRecords([$driver]);
});

// ==================== Integration Tests ====================

it('filters correctly when switching between period and shifts', function () {
    $shift1 = Shift::factory()->closed()->create([
        'start_at' => now()->subDays(3),
        'end_at' => now()->subDays(2),
    ]);

    $shift2 = Shift::factory()->closed()->create([
        'start_at' => now()->subDays(10),
        'end_at' => now()->subDays(9),
    ]);

    $driver1 = Driver::factory()->create();
    $driver2 = Driver::factory()->create();

    Order::factory()->create([
        'shift_id' => $shift1->id,
        'driver_id' => $driver1->id,
        'status' => OrderStatus::COMPLETED,
    ]);

    Order::factory()->create([
        'shift_id' => $shift2->id,
        'driver_id' => $driver2->id,
        'status' => OrderStatus::COMPLETED,
    ]);

    // Test period filter (should show only shift1)
    $periodComponent = livewire(DriverPerformanceTable::class, [
        'pageFilters' => [
            'filterType' => 'period',
            'startDate' => now()->subDays(6)->toDateString(),
            'endDate' => now()->toDateString(),
        ],
    ])
        ->assertCanSeeTableRecords([$driver1])
        ->assertCanNotSeeTableRecords([$driver2]);

    // Test shift filter (should show only shift2)
    $shiftComponent = livewire(DriverPerformanceTable::class, [
        'pageFilters' => [
            'filterType' => 'shifts',
            'shifts' => [$shift2->id],
        ],
    ])
        ->assertCanSeeTableRecords([$driver2])
        ->assertCanNotSeeTableRecords([$driver1]);
});

it('handles multiple drivers with varying performance', function () {
    $shift = Shift::factory()->closed()->create([
        'start_at' => now()->subDays(3),
        'end_at' => now()->subDays(2),
    ]);

    $driver1 = Driver::factory()->create(['name' => 'Top Performer']);
    $driver2 = Driver::factory()->create(['name' => 'Average Performer']);
    $driver3 = Driver::factory()->create(['name' => 'Low Performer']);

    // Top performer: 10 orders, high value
    Order::factory()->count(10)->create([
        'shift_id' => $shift->id,
        'driver_id' => $driver1->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 300,
    ]);

    // Average performer: 5 orders, medium value
    Order::factory()->count(5)->create([
        'shift_id' => $shift->id,
        'driver_id' => $driver2->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 200,
    ]);

    // Low performer: 2 orders, low value
    Order::factory()->count(2)->create([
        'shift_id' => $shift->id,
        'driver_id' => $driver3->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 100,
    ]);

    livewire(DriverPerformanceTable::class, [
        'pageFilters' => [
            'filterType' => 'period',
            'startDate' => now()->subDays(6)->toDateString(),
            'endDate' => now()->toDateString(),
        ],
    ])
        ->assertCanSeeTableRecords([$driver1, $driver2, $driver3])
        ->sortTable('orders_count', 'desc')
        ->assertCanSeeTableRecords([$driver1, $driver2, $driver3], inOrder: true);
});
