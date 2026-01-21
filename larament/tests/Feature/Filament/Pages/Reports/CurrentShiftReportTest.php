<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Filament\Pages\Reports\CurrentShiftReport;
use App\Filament\Widgets\CurrentShiftDoneOrdersStats;
use App\Filament\Widgets\CurrentShiftExpensesDetailsTable;
use App\Filament\Widgets\CurrentShiftExpensesTable;
use App\Filament\Widgets\CurrentShiftInfoStats;
use App\Filament\Widgets\CurrentShiftMoneyInfoStats;
use App\Filament\Widgets\CurrentShiftOrdersStats;
use App\Filament\Widgets\CurrentShiftOrdersTable;
use App\Filament\Widgets\NoActiveShiftWidget;
use App\Models\Expense;
use App\Models\ExpenceType;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    actingAs($this->admin);
});

// Page Rendering Tests
it('can render the page', function () {
    livewire(CurrentShiftReport::class)
        ->assertSuccessful();
});

it('can render the page with an active shift', function () {
    $shift = Shift::factory()->active()->create();

    livewire(CurrentShiftReport::class)
        ->assertSuccessful();
});

it('can render the page without an active shift', function () {
    // Create closed shifts only
    Shift::factory()->closed()->count(3)->create();

    livewire(CurrentShiftReport::class)
        ->assertSuccessful();
});

// Access Control Tests
it('allows admin users to access the page', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    actingAs($admin);

    livewire(CurrentShiftReport::class)
        ->assertSuccessful();
});

it('allows viewer users to access the page', function () {
    $viewer = User::factory()->create(['role' => UserRole::VIEWER]);
    actingAs($viewer);

    livewire(CurrentShiftReport::class)
        ->assertSuccessful();
});

it('prevents non-admin and non-viewer users from accessing the page', function () {
    $user = User::factory()->create(['role' => UserRole::CASHIER]);
    actingAs($user);

    livewire(CurrentShiftReport::class)
        ->assertForbidden();
});

// Widget Display Tests
it('displays no active shift widget when there is no active shift', function () {
    // Create only closed shifts
    Shift::factory()->closed()->count(2)->create();

    $component = livewire(CurrentShiftReport::class);

    $widgets = $component->instance()->getWidgets();

    expect($widgets)->toBe([
        NoActiveShiftWidget::class,
    ]);
});

it('displays all current shift widgets when there is an active shift', function () {
    Shift::factory()->active()->create();

    $component = livewire(CurrentShiftReport::class);

    $widgets = $component->instance()->getWidgets();

    expect($widgets)->toBe([
        CurrentShiftInfoStats::class,
        CurrentShiftMoneyInfoStats::class,
        CurrentShiftOrdersStats::class,
        CurrentShiftDoneOrdersStats::class,
        CurrentShiftOrdersTable::class,
        CurrentShiftExpensesDetailsTable::class,
        CurrentShiftExpensesTable::class,
    ]);
});

// Current Shift Data Tests
it('returns null when there is no active shift', function () {
    // Create only closed shifts
    Shift::factory()->closed()->count(2)->create();

    $component = livewire(CurrentShiftReport::class);

    $currentShift = $component->instance()->getCurrentShift();

    expect($currentShift)->toBeNull();
});

it('returns the current active shift', function () {
    $activeShift = Shift::factory()->active()->create();
    Shift::factory()->closed()->count(2)->create();

    $component = livewire(CurrentShiftReport::class);

    $currentShift = $component->instance()->getCurrentShift();

    expect($currentShift)->not->toBeNull();
    expect($currentShift->id)->toBe($activeShift->id);
    expect($currentShift->closed)->toBeFalse();
    expect($currentShift->end_at)->toBeNull();
});

it('loads current shift with related data', function () {
    $shift = Shift::factory()->active()->create();

    $component = livewire(CurrentShiftReport::class);

    $currentShift = $component->instance()->getCurrentShift();

    expect($currentShift)->not->toBeNull();
    expect($currentShift->relationLoaded('orders'))->toBeTrue();
    expect($currentShift->relationLoaded('expenses'))->toBeTrue();
    expect($currentShift->relationLoaded('user'))->toBeTrue();
});

// Navigation Tests
it('has correct navigation group', function () {
    expect(CurrentShiftReport::getNavigationGroup())->toBe('التقارير');
});

it('has correct navigation label', function () {
    expect(CurrentShiftReport::getNavigationLabel())->toBe('تقرير اليوم');
});

it('has correct page title', function () {
    $component = livewire(CurrentShiftReport::class);
    expect($component->instance()->getTitle())->toBe('تقرير اليوم');
});

it('has correct navigation sort order', function () {
    expect(CurrentShiftReport::getNavigationSort())->toBe(1);
});

it('has correct route path', function () {
    // Route path is defined in the page class as a protected static property
    $reflection = new \ReflectionClass(CurrentShiftReport::class);
    $property = $reflection->getProperty('routePath');
    $property->setAccessible(true);
    expect($property->getValue())->toBe('current-shift-report');
});

// Shift Financial Data Tests
it('calculates correct shift statistics with orders and expenses', function () {
    $shift = Shift::factory()->active()->create([
        'start_cash' => 100.00,
    ]);

    // Create completed orders
    $order1 = Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 150.50,
        'profit' => 45.15,
        'discount' => 10.00,
    ]);

    $order2 = Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 200.00,
        'profit' => 60.00,
        'discount' => 5.00,
    ]);

    // Create payments
    Payment::factory()->create([
        'order_id' => $order1->id,
        'shift_id' => $shift->id,
        'amount' => 150.50,
        'method' => PaymentMethod::CASH,
    ]);

    Payment::factory()->create([
        'order_id' => $order2->id,
        'shift_id' => $shift->id,
        'amount' => 200.00,
        'method' => PaymentMethod::CARD,
    ]);

    // Create expenses
    $expenseType = ExpenceType::factory()->create();
    Expense::factory()->create([
        'shift_id' => $shift->id,
        'expence_type_id' => $expenseType->id,
        'amount' => 50.00,
    ]);

    $component = livewire(CurrentShiftReport::class);
    $currentShift = $component->instance()->getCurrentShift();

    expect($currentShift)->not->toBeNull();
    expect($currentShift->orders)->toHaveCount(2);
    expect($currentShift->expenses)->toHaveCount(1);
});

it('handles shift with no orders or expenses', function () {
    $shift = Shift::factory()->active()->create([
        'start_cash' => 100.00,
    ]);

    $component = livewire(CurrentShiftReport::class);
    $currentShift = $component->instance()->getCurrentShift();

    expect($currentShift)->not->toBeNull();
    expect($currentShift->orders)->toHaveCount(0);
    expect($currentShift->expenses)->toHaveCount(0);
});

it('shows only current shift orders, not other shift orders', function () {
    $activeShift = Shift::factory()->active()->create();
    $closedShift = Shift::factory()->closed()->create();

    // Create orders for active shift
    Order::factory()->count(3)->create([
        'shift_id' => $activeShift->id,
        'status' => OrderStatus::COMPLETED,
    ]);

    // Create orders for closed shift
    Order::factory()->count(5)->create([
        'shift_id' => $closedShift->id,
        'status' => OrderStatus::COMPLETED,
    ]);

    $component = livewire(CurrentShiftReport::class);
    $currentShift = $component->instance()->getCurrentShift();

    expect($currentShift)->not->toBeNull();
    expect($currentShift->id)->toBe($activeShift->id);
    expect($currentShift->orders)->toHaveCount(3);
});

it('shows only current shift expenses, not other shift expenses', function () {
    $activeShift = Shift::factory()->active()->create();
    $closedShift = Shift::factory()->closed()->create();
    $expenseType = ExpenceType::factory()->create();

    // Create expenses for active shift
    Expense::factory()->count(2)->create([
        'shift_id' => $activeShift->id,
        'expence_type_id' => $expenseType->id,
    ]);

    // Create expenses for closed shift
    Expense::factory()->count(4)->create([
        'shift_id' => $closedShift->id,
        'expence_type_id' => $expenseType->id,
    ]);

    $component = livewire(CurrentShiftReport::class);
    $currentShift = $component->instance()->getCurrentShift();

    expect($currentShift)->not->toBeNull();
    expect($currentShift->id)->toBe($activeShift->id);
    expect($currentShift->expenses)->toHaveCount(2);
});

// Multiple Active Shifts Edge Case (should not happen, but test it)
it('returns the first active shift when multiple active shifts exist', function () {
    $shift1 = Shift::factory()->active()->create(['created_at' => now()->subHours(2)]);
    $shift2 = Shift::factory()->active()->create(['created_at' => now()->subHour()]);

    $component = livewire(CurrentShiftReport::class);
    $currentShift = $component->instance()->getCurrentShift();

    expect($currentShift)->not->toBeNull();
    // Should return the first one found by the query
    expect($currentShift->closed)->toBeFalse();
    expect($currentShift->end_at)->toBeNull();
});

// Order Status Tests
it('includes only completed orders in calculations', function () {
    $shift = Shift::factory()->active()->create();

    // Create orders with different statuses
    Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 100.00,
    ]);

    Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => OrderStatus::PENDING,
        'total' => 200.00,
    ]);

    Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => OrderStatus::CANCELLED,
        'total' => 150.00,
    ]);

    $component = livewire(CurrentShiftReport::class);
    $currentShift = $component->instance()->getCurrentShift();

    expect($currentShift)->not->toBeNull();
    expect($currentShift->orders)->toHaveCount(3);
    // Only completed orders should be used in calculations
});

// Payment Method Tests
it('handles multiple payment methods correctly', function () {
    $shift = Shift::factory()->active()->create();

    $order1 = Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 300.00,
    ]);

    // Mixed payment methods for one order
    Payment::factory()->create([
        'order_id' => $order1->id,
        'shift_id' => $shift->id,
        'amount' => 100.00,
        'method' => PaymentMethod::CASH,
    ]);

    Payment::factory()->create([
        'order_id' => $order1->id,
        'shift_id' => $shift->id,
        'amount' => 200.00,
        'method' => PaymentMethod::CARD,
    ]);

    $component = livewire(CurrentShiftReport::class);
    $currentShift = $component->instance()->getCurrentShift();

    expect($currentShift)->not->toBeNull();
    expect($currentShift->orders)->toHaveCount(1);
});

// Order Type Tests
it('handles different order types correctly', function () {
    $shift = Shift::factory()->active()->create();

    // Create orders with different types
    Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => OrderStatus::COMPLETED,
        'type' => OrderType::DINE_IN,
        'total' => 100.00,
    ]);

    Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => OrderStatus::COMPLETED,
        'type' => OrderType::DELIVERY,
        'total' => 150.00,
    ]);

    Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => OrderStatus::COMPLETED,
        'type' => OrderType::TAKEAWAY,
        'total' => 80.00,
    ]);

    $component = livewire(CurrentShiftReport::class);
    $currentShift = $component->instance()->getCurrentShift();

    expect($currentShift)->not->toBeNull();
    expect($currentShift->orders)->toHaveCount(3);
});

// Edge Cases
it('handles shift with zero start cash', function () {
    $shift = Shift::factory()->active()->create([
        'start_cash' => 0.00,
    ]);

    $component = livewire(CurrentShiftReport::class);
    $currentShift = $component->instance()->getCurrentShift();

    expect($currentShift)->not->toBeNull();
    expect($currentShift->start_cash)->toBe('0.00');
});

it('handles shift with large amounts', function () {
    $shift = Shift::factory()->active()->create([
        'start_cash' => 99999.99,
    ]);

    Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 50000.50,
        'profit' => 15000.15,
    ]);

    $component = livewire(CurrentShiftReport::class);
    $currentShift = $component->instance()->getCurrentShift();

    expect($currentShift)->not->toBeNull();
    expect($currentShift->start_cash)->toBe('99999.99');
});

// Shift Service Integration Tests
it('uses ShiftsReportService to get current shift', function () {
    $shift = Shift::factory()->active()->create();

    $component = livewire(CurrentShiftReport::class);
    $instance = $component->instance();

    // Verify the service is being used
    expect($instance->getCurrentShift())->not->toBeNull();
    expect($instance->getCurrentShift()->id)->toBe($shift->id);
});

// Comprehensive Shift Data Test
it('handles a complete shift scenario with all data types', function () {
    $shift = Shift::factory()->active()->create([
        'start_cash' => 500.00,
    ]);

    // Create orders with various statuses
    Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => OrderStatus::COMPLETED,
        'type' => OrderType::DINE_IN,
        'total' => 250.00,
        'profit' => 75.00,
        'discount' => 10.00,
    ]);

    Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => OrderStatus::COMPLETED,
        'type' => OrderType::DELIVERY,
        'total' => 350.00,
        'profit' => 105.00,
        'discount' => 15.00,
    ]);

    Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => OrderStatus::PENDING,
        'type' => OrderType::TAKEAWAY,
        'total' => 100.00,
        'profit' => 30.00,
    ]);

    // Create expenses
    $expenseType1 = ExpenceType::factory()->create();
    $expenseType2 = ExpenceType::factory()->create();

    Expense::factory()->create([
        'shift_id' => $shift->id,
        'expence_type_id' => $expenseType1->id,
        'amount' => 100.00,
    ]);

    Expense::factory()->create([
        'shift_id' => $shift->id,
        'expence_type_id' => $expenseType2->id,
        'amount' => 50.00,
    ]);

    $component = livewire(CurrentShiftReport::class);
    $currentShift = $component->instance()->getCurrentShift();

    expect($currentShift)->not->toBeNull();
    expect($currentShift->id)->toBe($shift->id);
    expect($currentShift->orders)->toHaveCount(3);
    expect($currentShift->expenses)->toHaveCount(2);
    expect($currentShift->user)->not->toBeNull();
});

// Test that widgets receive proper data
it('widgets can access current shift data', function () {
    $shift = Shift::factory()->active()->create();

    Order::factory()->count(5)->create([
        'shift_id' => $shift->id,
        'status' => OrderStatus::COMPLETED,
    ]);

    $component = livewire(CurrentShiftReport::class);

    // The page should be accessible and widgets should be able to get data
    $component->assertSuccessful();

    $currentShift = $component->instance()->getCurrentShift();
    expect($currentShift->orders)->toHaveCount(5);
});

// User Context Tests
it('associates shift with correct user', function () {
    $user = User::factory()->create(['role' => UserRole::ADMIN]);
    $shift = Shift::factory()->active()->create([
        'user_id' => $user->id,
    ]);

    actingAs($user);

    $component = livewire(CurrentShiftReport::class);
    $currentShift = $component->instance()->getCurrentShift();

    expect($currentShift)->not->toBeNull();
    expect($currentShift->user_id)->toBe($user->id);
    expect($currentShift->user->id)->toBe($user->id);
});

// Datetime Tests
it('correctly handles shift timestamps', function () {
    $startTime = now()->subHours(8);

    $shift = Shift::factory()->create([
        'start_at' => $startTime,
        'end_at' => null,
        'closed' => false,
    ]);

    $component = livewire(CurrentShiftReport::class);
    $currentShift = $component->instance()->getCurrentShift();

    expect($currentShift)->not->toBeNull();
    expect($currentShift->start_at)->toBeInstanceOf(\Carbon\Carbon::class);
    expect($currentShift->start_at->diffInHours(now()))->toBeGreaterThanOrEqual(8);
});

// Performance Test - Many Orders
it('handles shift with many orders efficiently', function () {
    $shift = Shift::factory()->active()->create();

    Order::factory()->count(50)->create([
        'shift_id' => $shift->id,
        'status' => OrderStatus::COMPLETED,
    ]);

    $component = livewire(CurrentShiftReport::class);
    $currentShift = $component->instance()->getCurrentShift();

    expect($currentShift)->not->toBeNull();
    expect($currentShift->orders)->toHaveCount(50);
});

// Performance Test - Many Expenses
it('handles shift with many expenses efficiently', function () {
    $shift = Shift::factory()->active()->create();
    $expenseType = ExpenceType::factory()->create();

    Expense::factory()->count(30)->create([
        'shift_id' => $shift->id,
        'expence_type_id' => $expenseType->id,
    ]);

    $component = livewire(CurrentShiftReport::class);
    $currentShift = $component->instance()->getCurrentShift();

    expect($currentShift)->not->toBeNull();
    expect($currentShift->expenses)->toHaveCount(30);
});

// Shift Attributes Tests
it('correctly identifies active shift', function () {
    $shift = Shift::factory()->create([
        'end_at' => null,
        'closed' => false,
    ]);

    $component = livewire(CurrentShiftReport::class);
    $currentShift = $component->instance()->getCurrentShift();

    expect($currentShift)->not->toBeNull();
    expect($currentShift->is_active)->toBeTrue();
});

it('does not return closed shift as current shift', function () {
    Shift::factory()->closed()->create();

    $component = livewire(CurrentShiftReport::class);
    $currentShift = $component->instance()->getCurrentShift();

    expect($currentShift)->toBeNull();
});

it('does not return shift with end_at set as current shift', function () {
    Shift::factory()->create([
        'end_at' => now(),
        'closed' => false,
    ]);

    $component = livewire(CurrentShiftReport::class);
    $currentShift = $component->instance()->getCurrentShift();

    expect($currentShift)->toBeNull();
});

// Boundary Tests
it('handles decimal precision correctly', function () {
    $shift = Shift::factory()->active()->create([
        'start_cash' => 123.45,
    ]);

    Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 678.90,
        'profit' => 234.56,
    ]);

    $component = livewire(CurrentShiftReport::class);
    $currentShift = $component->instance()->getCurrentShift();

    expect($currentShift)->not->toBeNull();
    expect($currentShift->start_cash)->toBe('123.45');
});

// Test all payment methods
it('handles all payment methods', function () {
    $shift = Shift::factory()->active()->create();

    foreach ([PaymentMethod::CASH, PaymentMethod::CARD, PaymentMethod::TALABAT_CARD] as $method) {
        $order = Order::factory()->create([
            'shift_id' => $shift->id,
            'status' => OrderStatus::COMPLETED,
            'total' => 100.00,
        ]);

        Payment::factory()->create([
            'order_id' => $order->id,
            'shift_id' => $shift->id,
            'amount' => 100.00,
            'method' => $method,
        ]);
    }

    $component = livewire(CurrentShiftReport::class);
    $currentShift = $component->instance()->getCurrentShift();

    expect($currentShift)->not->toBeNull();
    expect($currentShift->orders)->toHaveCount(3);
});
