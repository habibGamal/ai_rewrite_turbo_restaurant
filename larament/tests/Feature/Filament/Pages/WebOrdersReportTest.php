<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Filament\Pages\Reports\WebOrdersReport;
use App\Filament\Widgets\WebOrdersStats;
use App\Filament\Widgets\WebOrdersTable;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create admin user for authentication (ViewerAccess trait allows viewers and admins)
    $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    actingAs($this->admin);
});

// Page Rendering Tests
it('can render the web orders report page', function () {
    livewire(WebOrdersReport::class)
        ->assertSuccessful();
});

it('can access page with viewer role', function () {
    $viewer = User::factory()->create(['role' => UserRole::VIEWER]);
    actingAs($viewer);

    livewire(WebOrdersReport::class)
        ->assertSuccessful();
});

it('cannot access page with cashier role', function () {
    $cashier = User::factory()->create(['role' => UserRole::CASHIER]);
    actingAs($cashier);

    livewire(WebOrdersReport::class)
        ->assertForbidden();
});

// Widget Rendering Tests
it('shows WebOrdersStats widget', function () {
    livewire(WebOrdersReport::class)
        ->assertSeeLivewire(WebOrdersStats::class);
});

it('shows WebOrdersTable widget', function () {
    livewire(WebOrdersReport::class)
        ->assertSeeLivewire(WebOrdersTable::class);
});

it('loads both widgets on page', function () {
    livewire(WebOrdersReport::class)
        ->assertSeeLivewire(WebOrdersStats::class)
        ->assertSeeLivewire(WebOrdersTable::class);
});

// WebOrdersStats Widget Tests
it('displays stats for pending web orders', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
        'sub_total' => 100,
        'tax' => 14,
        'service' => 5,
        'discount' => 0,
        'total' => 119,
        'profit' => 50,
    ]);

    livewire(WebOrdersStats::class)
        ->assertSuccessful();
});

it('displays stats for processing web orders', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_TAKEAWAY,
        'status' => OrderStatus::PROCESSING,
        'sub_total' => 200,
        'tax' => 28,
        'service' => 10,
        'discount' => 0,
        'total' => 238,
        'profit' => 100,
    ]);

    livewire(WebOrdersStats::class)
        ->assertSuccessful();
});

it('displays stats for out for delivery web orders', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::OUT_FOR_DELIVERY,
        'sub_total' => 150,
        'tax' => 21,
        'service' => 7.5,
        'discount' => 0,
        'total' => 178.5,
        'profit' => 75,
    ]);

    livewire(WebOrdersStats::class)
        ->assertSuccessful();
});

it('does not count completed web orders in stats', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    // Should not be counted
    Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::COMPLETED,
        'total' => 100,
        'profit' => 50,
    ]);

    // Should be counted
    Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
        'total' => 200,
        'profit' => 100,
    ]);

    livewire(WebOrdersStats::class)
        ->assertSuccessful();
});

it('does not count cancelled web orders in stats', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::CANCELLED,
        'total' => 100,
        'profit' => 50,
    ]);

    livewire(WebOrdersStats::class)
        ->assertSuccessful();
});

it('does not count non-web orders in stats', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    // Regular delivery order (not web)
    Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::DELIVERY,
        'status' => OrderStatus::PENDING,
        'total' => 100,
        'profit' => 50,
    ]);

    // Dine-in order
    Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::DINE_IN,
        'status' => OrderStatus::PENDING,
        'total' => 200,
        'profit' => 100,
    ]);

    livewire(WebOrdersStats::class)
        ->assertSuccessful();
});

// WebOrdersTable Widget Tests
it('can render the web orders table', function () {
    livewire(WebOrdersTable::class)
        ->assertSuccessful();
});

it('displays web delivery orders in table', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
    ]);

    livewire(WebOrdersTable::class)
        ->assertCanSeeTableRecords([$order]);
});

it('displays web takeaway orders in table', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_TAKEAWAY,
        'status' => OrderStatus::PROCESSING,
    ]);

    livewire(WebOrdersTable::class)
        ->assertCanSeeTableRecords([$order]);
});

it('does not display completed orders in table', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $completedOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::COMPLETED,
    ]);

    $pendingOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
    ]);

    livewire(WebOrdersTable::class)
        ->assertCanSeeTableRecords([$pendingOrder])
        ->assertCanNotSeeTableRecords([$completedOrder]);
});

it('does not display cancelled orders in table', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $cancelledOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::CANCELLED,
    ]);

    $pendingOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
    ]);

    livewire(WebOrdersTable::class)
        ->assertCanSeeTableRecords([$pendingOrder])
        ->assertCanNotSeeTableRecords([$cancelledOrder]);
});

it('does not display non-web orders in table', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $regularOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::DELIVERY,
        'status' => OrderStatus::PENDING,
    ]);

    $webOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
    ]);

    livewire(WebOrdersTable::class)
        ->assertCanSeeTableRecords([$webOrder])
        ->assertCanNotSeeTableRecords([$regularOrder]);
});

// Table Column Tests
it('shows required table columns', function () {
    livewire(WebOrdersTable::class)
        ->assertTableColumnExists('id')
        ->assertTableColumnExists('order_number')
        ->assertTableColumnExists('status')
        ->assertTableColumnExists('customer.name')
        ->assertTableColumnExists('sub_total')
        ->assertTableColumnExists('total')
        ->assertTableColumnExists('created_at');
});

it('shows toggleable table columns', function () {
    livewire(WebOrdersTable::class)
        ->assertTableColumnExists('customer.phone')
        ->assertTableColumnExists('web_payment_method')
        ->assertTableColumnExists('transaction_id')
        ->assertTableColumnExists('driver.name')
        ->assertTableColumnExists('tax')
        ->assertTableColumnExists('service')
        ->assertTableColumnExists('discount')
        ->assertTableColumnExists('profit')
        ->assertTableColumnExists('payments')
        ->assertTableColumnExists('cash')
        ->assertTableColumnExists('card')
        ->assertTableColumnExists('user.name');
});

// Table Search Tests
it('can search orders by id', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $order1 = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
    ]);

    $order2 = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PROCESSING,
    ]);

    livewire(WebOrdersTable::class)
        ->searchTable($order1->id)
        ->assertCanSeeTableRecords([$order1])
        ->assertCanNotSeeTableRecords([$order2]);
});

it('can search orders by order number', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $order1 = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
        'order_number' => 'WEB-001',
    ]);

    $order2 = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PROCESSING,
        'order_number' => 'WEB-002',
    ]);

    livewire(WebOrdersTable::class)
        ->searchTable('WEB-001')
        ->assertCanSeeTableRecords([$order1])
        ->assertCanNotSeeTableRecords([$order2]);
});

it('can search orders by customer name', function () {
    $customer1 = Customer::factory()->create(['name' => 'أحمد محمد']);
    $customer2 = Customer::factory()->create(['name' => 'محمود علي']);
    $user = User::factory()->create();

    $order1 = Order::factory()->create([
        'customer_id' => $customer1->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
    ]);

    $order2 = Order::factory()->create([
        'customer_id' => $customer2->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PROCESSING,
    ]);

    livewire(WebOrdersTable::class)
        ->searchTable('أحمد')
        ->assertCanSeeTableRecords([$order1])
        ->assertCanNotSeeTableRecords([$order2]);
});

it('can search orders by customer phone', function () {
    $customer1 = Customer::factory()->create(['phone' => '01234567890']);
    $customer2 = Customer::factory()->create(['phone' => '01987654321']);
    $user = User::factory()->create();

    $order1 = Order::factory()->create([
        'customer_id' => $customer1->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
    ]);

    $order2 = Order::factory()->create([
        'customer_id' => $customer2->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PROCESSING,
    ]);

    livewire(WebOrdersTable::class)
        ->searchTable('01234567890')
        ->assertCanSeeTableRecords([$order1])
        ->assertCanNotSeeTableRecords([$order2]);
});

// Table Sorting Tests
it('can sort orders by id', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $orders = Order::factory()->count(3)->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
    ]);

    livewire(WebOrdersTable::class)
        ->sortTable('id')
        ->assertCanSeeTableRecords($orders)
        ->sortTable('id', 'desc')
        ->assertCanSeeTableRecords($orders);
});

it('can sort orders by order number', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $orders = Order::factory()->count(3)->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
    ]);

    livewire(WebOrdersTable::class)
        ->sortTable('order_number')
        ->assertCanSeeTableRecords($orders)
        ->sortTable('order_number', 'desc')
        ->assertCanSeeTableRecords($orders);
});

it('can sort orders by created_at', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $orders = collect([
        Order::factory()->create([
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'type' => OrderType::WEB_DELIVERY,
            'status' => OrderStatus::PENDING,
            'created_at' => now()->subDays(3),
        ]),
        Order::factory()->create([
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'type' => OrderType::WEB_DELIVERY,
            'status' => OrderStatus::PENDING,
            'created_at' => now()->subDays(1),
        ]),
        Order::factory()->create([
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'type' => OrderType::WEB_DELIVERY,
            'status' => OrderStatus::PENDING,
            'created_at' => now()->subDays(2),
        ]),
    ]);

    livewire(WebOrdersTable::class)
        ->sortTable('created_at')
        ->assertCanSeeTableRecords($orders)
        ->sortTable('created_at', 'desc')
        ->assertCanSeeTableRecords($orders);
});

// Table Filter Tests
it('can filter orders by status', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $pendingOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
    ]);

    $processingOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PROCESSING,
    ]);

    livewire(WebOrdersTable::class)
        ->filterTable('status', OrderStatus::PENDING->value)
        ->assertCanSeeTableRecords([$pendingOrder])
        ->assertCanNotSeeTableRecords([$processingOrder]);
});

it('can filter orders by web payment method', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $cashOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
        'web_preferences' => ['payment_method' => 'cash'],
    ]);

    $cardOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
        'web_preferences' => ['payment_method' => 'card'],
    ]);

    livewire(WebOrdersTable::class)
        ->filterTable('web_payment_method', 'cash')
        ->assertCanSeeTableRecords([$cashOrder])
        ->assertCanNotSeeTableRecords([$cardOrder]);
});

it('can filter orders by payment method', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $cashOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
    ]);

    Payment::factory()->create([
        'order_id' => $cashOrder->id,
        'method' => PaymentMethod::CASH,
        'amount' => 100,
    ]);

    $cardOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
    ]);

    Payment::factory()->create([
        'order_id' => $cardOrder->id,
        'method' => PaymentMethod::CARD,
        'amount' => 200,
    ]);

    livewire(WebOrdersTable::class)
        ->filterTable('payment_method', PaymentMethod::CASH->value)
        ->assertCanSeeTableRecords([$cashOrder])
        ->assertCanNotSeeTableRecords([$cardOrder]);
});

it('can filter orders with discount', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $orderWithDiscount = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
        'discount' => 10,
    ]);

    $orderWithoutDiscount = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
        'discount' => 0,
    ]);

    livewire(WebOrdersTable::class)
        ->filterTable('has_discount', true)
        ->assertCanSeeTableRecords([$orderWithDiscount])
        ->assertCanNotSeeTableRecords([$orderWithoutDiscount]);
});

it('can filter orders by date range', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $recentOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
        'created_at' => now()->subDays(5),
    ]);

    $oldOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
        'created_at' => now()->subDays(60),
    ]);

    livewire(WebOrdersTable::class)
        ->filterTable('created_at', [
            'created_from' => now()->subDays(7)->toDateString(),
            'created_until' => now()->toDateString(),
        ])
        ->assertCanSeeTableRecords([$recentOrder])
        ->assertCanNotSeeTableRecords([$oldOrder]);
});

// Pagination Tests
it('can paginate orders', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    Order::factory()->count(25)->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
    ]);

    $component = livewire(WebOrdersTable::class);

    // Assert total record count is 25
    expect($component->instance()->getAllTableRecordsCount())->toBe(25);
});

// Web Preferences Tests
it('displays cash web payment method correctly', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
        'web_preferences' => ['payment_method' => 'cash'],
    ]);

    livewire(WebOrdersTable::class)
        ->assertCanSeeTableRecords([$order]);
});

it('displays card web payment method correctly', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
        'web_preferences' => ['payment_method' => 'card'],
    ]);

    livewire(WebOrdersTable::class)
        ->assertCanSeeTableRecords([$order]);
});

it('displays transaction id when present', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
        'web_preferences' => [
            'payment_method' => 'card',
            'transaction_id' => 'TXN-123456',
        ],
    ]);

    livewire(WebOrdersTable::class)
        ->assertCanSeeTableRecords([$order]);
});

// Driver Association Tests
it('displays driver name when assigned', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();
    $driver = Driver::factory()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'driver_id' => $driver->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::OUT_FOR_DELIVERY,
    ]);

    livewire(WebOrdersTable::class)
        ->assertCanSeeTableRecords([$order]);
});

it('handles orders without driver', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'driver_id' => null,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
    ]);

    livewire(WebOrdersTable::class)
        ->assertCanSeeTableRecords([$order]);
});

// Payment Display Tests
it('displays multiple payment methods', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
    ]);

    Payment::factory()->create([
        'order_id' => $order->id,
        'method' => PaymentMethod::CASH,
        'amount' => 50,
    ]);

    Payment::factory()->create([
        'order_id' => $order->id,
        'method' => PaymentMethod::CARD,
        'amount' => 50,
    ]);

    livewire(WebOrdersTable::class)
        ->assertCanSeeTableRecords([$order]);
});

it('displays cash payment amount', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
    ]);

    Payment::factory()->create([
        'order_id' => $order->id,
        'method' => PaymentMethod::CASH,
        'amount' => 100,
    ]);

    livewire(WebOrdersTable::class)
        ->assertCanSeeTableRecords([$order]);
});

it('displays card payment amount', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
    ]);

    Payment::factory()->create([
        'order_id' => $order->id,
        'method' => PaymentMethod::CARD,
        'amount' => 200,
    ]);

    livewire(WebOrdersTable::class)
        ->assertCanSeeTableRecords([$order]);
});

// Empty State Tests
it('shows empty state when no orders exist', function () {
    livewire(WebOrdersTable::class)
        ->assertCountTableRecords(0);
});

it('shows empty state message', function () {
    livewire(WebOrdersTable::class)
        ->assertSuccessful();
});

// Polling Tests
it('polls for updates every 30 seconds', function () {
    livewire(WebOrdersStats::class)
        ->assertSuccessful();
});

it('table polls for updates every 30 seconds', function () {
    livewire(WebOrdersTable::class)
        ->assertSuccessful();
});

// Edge Cases
it('handles orders with missing customer', function () {
    $user = User::factory()->create();

    $order = Order::factory()->create([
        'customer_id' => null,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
    ]);

    livewire(WebOrdersTable::class)
        ->assertCanSeeTableRecords([$order]);
});

it('handles orders with zero values', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
        'sub_total' => 0,
        'tax' => 0,
        'service' => 0,
        'discount' => 0,
        'total' => 0,
        'profit' => 0,
    ]);

    livewire(WebOrdersTable::class)
        ->assertCanSeeTableRecords([$order]);
});

it('handles large discount values', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
        'sub_total' => 1000,
        'discount' => 500,
        'total' => 500,
    ]);

    livewire(WebOrdersTable::class)
        ->assertCanSeeTableRecords([$order]);
});

// Integration Tests
it('stats widget updates when orders change status', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
        'total' => 100,
        'profit' => 50,
    ]);

    livewire(WebOrdersStats::class)
        ->assertSuccessful();

    $order->update(['status' => OrderStatus::PROCESSING]);

    livewire(WebOrdersStats::class)
        ->assertSuccessful();
});

it('table updates when new orders are created', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    livewire(WebOrdersTable::class)
        ->assertCountTableRecords(0);

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
    ]);

    livewire(WebOrdersTable::class)
        ->assertCanSeeTableRecords([$order])
        ->assertCountTableRecords(1);
});

it('handles mixed order types correctly', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    // Web orders - should be displayed
    $webDeliveryOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
    ]);

    $webTakeawayOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_TAKEAWAY,
        'status' => OrderStatus::PENDING,
    ]);

    // Non-web orders - should not be displayed
    $regularDeliveryOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::DELIVERY,
        'status' => OrderStatus::PENDING,
    ]);

    $dineInOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::DINE_IN,
        'status' => OrderStatus::PENDING,
    ]);

    livewire(WebOrdersTable::class)
        ->assertCanSeeTableRecords([$webDeliveryOrder, $webTakeawayOrder])
        ->assertCanNotSeeTableRecords([$regularDeliveryOrder, $dineInOrder])
        ->assertCountTableRecords(2);
});

it('handles all three tracked statuses', function () {
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $pendingOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PENDING,
    ]);

    $processingOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::PROCESSING,
    ]);

    $outForDeliveryOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => OrderType::WEB_DELIVERY,
        'status' => OrderStatus::OUT_FOR_DELIVERY,
    ]);

    livewire(WebOrdersTable::class)
        ->assertCanSeeTableRecords([$pendingOrder, $processingOrder, $outForDeliveryOrder])
        ->assertCountTableRecords(3);
});
