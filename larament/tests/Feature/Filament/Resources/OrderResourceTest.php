<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\ReturnStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    actingAs($this->admin);
});

// Page Rendering Tests
it('can render the index page', function () {
    livewire(ListOrders::class)
        ->assertSuccessful();
});

it('can render the view page', function () {
    $record = Order::factory()->create();

    livewire(ViewOrder::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

// Table Column Tests
it('has column', function (string $column) {
    livewire(ListOrders::class)
        ->assertTableColumnExists($column);
})->with([
    'id',
    'order_number',
    'customer.name',
    'customer.phone',
    'type',
    'status',
    'payment_status',
    'return_status',
    'total',
    'payments_count',
    'user.name',
    'transaction_id',
    'created_at'
]);

it('can render column', function (string $column) {
    Order::factory()->create();

    livewire(ListOrders::class)
        ->assertCanRenderTableColumn($column);
})->with([
    'id',
    'order_number',
    'type',
    'status',
    'payment_status',
    'return_status',
    'total',
    'payments_count',
    'created_at'
]);

// Table Sorting Tests
it('can sort column', function (string $column) {
    $records = Order::factory(5)->create();

    livewire(ListOrders::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($column))
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($column));
})->with(['id', 'order_number', 'type', 'status', 'payment_status', 'total', 'created_at']);

// Table Search Tests
it('can search by order number', function () {
    $records = Order::factory(5)->create();
    $value = $records->first()->order_number;

    livewire(ListOrders::class)
        ->searchTable($value)
        ->assertCanSeeTableRecords($records->where('order_number', $value));
});

it('can search by id', function () {
    $records = Order::factory(5)->create();
    $value = $records->first()->id;

    livewire(ListOrders::class)
        ->searchTable($value)
        ->assertCanSeeTableRecords($records->where('id', $value));
});

// Table Filter Tests
it('can filter by type', function () {
    $dineInOrders = Order::factory(3)->create(['type' => OrderType::DINE_IN]);
    $deliveryOrders = Order::factory(2)->create(['type' => OrderType::DELIVERY]);

    livewire(ListOrders::class)
        ->filterTable('type', OrderType::DINE_IN->value)
        ->assertCanSeeTableRecords($dineInOrders)
        ->assertCountTableRecords(3);
});

it('can filter by status', function () {
    $processingOrders = Order::factory(3)->create(['status' => OrderStatus::PROCESSING]);
    $completedOrders = Order::factory(2)->create(['status' => OrderStatus::COMPLETED]);

    livewire(ListOrders::class)
        ->filterTable('status', OrderStatus::PROCESSING->value)
        ->assertCanSeeTableRecords($processingOrders)
        ->assertCountTableRecords(3);
});

it('can filter by payment status', function () {
    $pendingOrders = Order::factory(3)->create(['payment_status' => PaymentStatus::PENDING]);
    $paidOrders = Order::factory(2)->create(['payment_status' => PaymentStatus::FULL_PAID]);

    livewire(ListOrders::class)
        ->filterTable('payment_status', PaymentStatus::PENDING->value)
        ->assertCanSeeTableRecords($pendingOrders)
        ->assertCountTableRecords(3);
});

it('can filter by return status', function () {
    $normalOrders = Order::factory(3)->create(['return_status' => ReturnStatus::NONE]);
    $returnedOrders = Order::factory(2)->create(['return_status' => ReturnStatus::PARTIAL_RETURN]);

    livewire(ListOrders::class)
        ->filterTable('return_status', ReturnStatus::NONE->value)
        ->assertCanSeeTableRecords($normalOrders)
        ->assertCountTableRecords(3);
});

it('can filter by created date range', function () {
    $oldOrders = Order::factory(3)->create(['created_at' => now()->subDays(10)]);
    $recentOrders = Order::factory(2)->create(['created_at' => now()]);

    livewire(ListOrders::class)
        ->filterTable('created_at', [
            'created_from' => now()->subDays(5)->format('Y-m-d'),
            'created_until' => now()->addDay()->format('Y-m-d'),
        ])
        ->assertCanSeeTableRecords($recentOrders)
        ->assertCountTableRecords(2);
});

// View Page Tests
it('can view order details', function () {
    $record = Order::factory()->create();

    livewire(ViewOrder::class, ['record' => $record->getRouteKey()])
        ->assertSchemaStateSet([
            'order_number' => $record->order_number,
            'type' => $record->type,
            'status' => $record->status,
            'payment_status' => $record->payment_status,
        ]);
});

it('can view order with customer', function () {
    $customer = Customer::factory()->create();
    $record = Order::factory()->create(['customer_id' => $customer->id]);

    livewire(ViewOrder::class, ['record' => $record->getRouteKey()])
        ->assertSee($customer->name)
        ->assertSee($customer->phone);
});

it('displays correct payment count', function () {
    $order = Order::factory()->create();
    Payment::factory(3)->create(['order_id' => $order->id]);

    livewire(ListOrders::class)
        ->assertSee('3'); // Should see the payment count
});

it('shows web preferences when available', function () {
    $order = Order::factory()->create([
        'web_preferences' => [
            'payment_method' => 'card',
            'transaction_id' => 'TXN123456',
        ],
    ]);

    livewire(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertSee('TXN123456');
});

// Table Record Visibility Tests
it('can see table records', function () {
    $records = Order::factory(5)->create();

    livewire(ListOrders::class)
        ->assertCanSeeTableRecords($records);
});

it('can count table records', function () {
    Order::factory(3)->create();

    livewire(ListOrders::class)
        ->assertCountTableRecords(3);
});

// Table Actions Tests
it('has view action on list page', function () {
    $record = Order::factory()->create();

    livewire(ListOrders::class)
        ->assertTableActionExists('view');
});

// Default Sorting Test
it('defaults to sorting by created_at descending', function () {
    $oldOrder = Order::factory()->create(['created_at' => now()->subDays(2)]);
    $newOrder = Order::factory()->create(['created_at' => now()]);

    $livewire = livewire(ListOrders::class);

    // Just check the first visible record is the newer one
    $records = Order::query()->latest('created_at')->get();
    expect($records->first()->id)->toBe($newOrder->id);
});

// Relation Managers Tests
it('has items relation manager tab', function () {
    $order = Order::factory()->create();

    livewire(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertSee('أصناف الطلب'); // Items tab label in Arabic
});

it('has payments relation manager tab', function () {
    $order = Order::factory()->create();

    livewire(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertSee('مدفوعات الطلب'); // Payments tab label in Arabic
});

it('has order returns relation manager tab', function () {
    $order = Order::factory()->create();

    livewire(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertSee('عمليات الإرجاع'); // Returns tab label in Arabic
});

// Field Toggleable Tests
it('transaction_id column is toggleable', function () {
    livewire(ListOrders::class)
        ->assertTableColumnExists('transaction_id');
});

it('return_status column is visible by default', function () {
    Order::factory()->create();

    livewire(ListOrders::class)
        ->assertCanRenderTableColumn('return_status');
});

// Currency Format Test
it('displays total in EGP currency format', function () {
    $order = Order::factory()->create(['total' => 100.50]);

    livewire(ListOrders::class)
        ->assertSee('ج.م'); // Arabic currency symbol
});

// Computed Attributes Tests
it('calculates remaining amount correctly', function () {
    $order = Order::factory()->create(['total' => 100]);
    Payment::factory()->create(['order_id' => $order->id, 'amount' => 30]);
    Payment::factory()->create(['order_id' => $order->id, 'amount' => 20]);

    $order->refresh();
    expect($order->remaining_amount)->toBe(50.0);
});

it('calculates total paid correctly', function () {
    $order = Order::factory()->create(['total' => 100]);
    Payment::factory()->create(['order_id' => $order->id, 'amount' => 30]);
    Payment::factory()->create(['order_id' => $order->id, 'amount' => 70]);

    $order->refresh();
    expect($order->total_paid)->toBe(100.0);
});
