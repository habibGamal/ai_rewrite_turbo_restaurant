<?php

use App\Core\Events\OrderChargesChanged;
use App\Core\Orders\SalesBreakdown;
use App\Enums\OrderStatus;
use App\Enums\SettingKey;
use App\Events\Orders\OrderChargesChangedBroadcast;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Services\Orders\OrderCalculationService;
use App\Services\Orders\OrderService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->actingAs($this->user);
    $this->shift = Shift::factory()->active()->create(['user_id' => $this->user->id]);
    $this->product = Product::factory()->create(['price' => 100, 'cost' => 40]);
});

function chargedOrder(array $attributes = []): Order
{
    $order = Order::factory()->create([
        'user_id' => test()->user->id,
        'shift_id' => test()->shift->id,
        'type' => 'takeaway',
        'status' => OrderStatus::PROCESSING,
        'discount' => 0,
        'temp_discount_percent' => 0,
        ...$attributes,
    ]);
    $order->items()->create([
        'product_id' => test()->product->id,
        'quantity' => 1,
        'price' => 100,
        'cost' => 40,
        'total' => 100,
    ]);

    return $order;
}

it('defaults the charge columns to zero and guests to null', function () {
    $order = chargedOrder()->fresh();

    expect($order->fees_total)->toBe('0.00')
        ->and($order->equipment_total)->toBe('0.00')
        ->and($order->equipment_cost)->toBe('0.00')
        ->and($order->guests_count)->toBeNull();
});

it('keeps totals and profit unchanged for orders without fees or equipment', function () {
    $order = app(OrderCalculationService::class)->calculateOrderTotals(chargedOrder());

    expect($order->total)->toBe('100.00')
        ->and($order->profit)->toBe('60.00');
});

it('adds fees and equipment to the total and takes equipment cost from profit', function () {
    $order = chargedOrder(['fees_total' => 25, 'equipment_total' => 50, 'equipment_cost' => 10]);

    $order = app(OrderCalculationService::class)->calculateOrderTotals($order);

    expect($order->total)->toBe('175.00')
        ->and($order->profit)->toBe('125.00'); // 175 − 40 items − 10 equipment
});

it('applies service and discount to items only', function () {
    app(SettingsService::class)->set(SettingKey::DINE_IN_SERVICE_CHARGE->value, '0.10');
    $order = chargedOrder([
        'type' => 'dine_in',
        'dine_table_number' => '3',
        'temp_discount_percent' => 10,
        'fees_total' => 20,
        'equipment_total' => 30,
    ]);

    $order = app(OrderCalculationService::class)->calculateOrderTotals($order);

    // items 100 + service 10 − discount 10 + fees 20 + equipment 30
    expect($order->service)->toBe('10.00')
        ->and($order->discount)->toBe('10.00')
        ->and($order->total)->toBe('150.00');
});

it('keeps ceil rounding on the whole total', function () {
    $order = chargedOrder(['fees_total' => 10.25]);

    $order = app(OrderCalculationService::class)->calculateOrderTotals($order);

    expect($order->total)->toBe('111.00');
});

it('keeps equipment cost out of profit after completion', function () {
    openInventoryDay($this->product);
    $order = chargedOrder(['equipment_total' => 50, 'equipment_cost' => 15]);

    $completed = app(OrderService::class)->completeOrder($order->id, ['cash' => 150]);

    expect($completed->total)->toBe('150.00')
        ->and((float) $completed->profit)->toBe(150.0 - 40.0 - 15.0);
});

it('splits sales for one order and for a query of orders', function () {
    $first = app(OrderCalculationService::class)->calculateOrderTotals(
        chargedOrder(['fees_total' => 10.5, 'equipment_total' => 20, 'equipment_cost' => 5])
    );
    app(OrderCalculationService::class)->calculateOrderTotals(chargedOrder(['temp_discount_percent' => 50]));

    $one = SalesBreakdown::fromOrder($first);
    expect($one->toArray())->toMatchArray([
        'items' => 100.0,
        'fees' => 10.5,
        'equipment' => 20.0,
        'total' => 131.0,
        'rounding' => 0.5,
        'equipment_cost' => 5.0,
    ]);

    $all = SalesBreakdown::fromQuery(Order::query()->where('shift_id', $this->shift->id));
    expect($all->items)->toBe(200.0)
        ->and($all->discount)->toBe(50.0)
        ->and($all->fees)->toBe(10.5)
        ->and($all->equipment)->toBe(20.0)
        ->and($all->total)->toBe(181.0)
        ->and($all->plus($one)->total)->toBe(312.0);
});

it('hides zero fee and equipment rows in the labelled split', function () {
    $plain = SalesBreakdown::fromOrder(app(OrderCalculationService::class)->calculateOrderTotals(chargedOrder()));
    $charged = new SalesBreakdown(items: 100, fees: 5, equipment: 7, total: 112);

    expect(array_keys($plain->labelled()))->toBe(['الأصناف', 'الخدمة', 'الخصم', 'الإجمالي'])
        ->and($charged->labelled())->toHaveKeys(['رسوم إضافية', 'المعدات']);
});

it('broadcasts charge changes only after the transaction commits', function () {
    Event::fake([OrderChargesChangedBroadcast::class]);
    $order = chargedOrder();

    DB::transaction(function () use ($order) {
        OrderChargesChanged::dispatch($order, OrderChargesChanged::FEES);
        Event::assertNotDispatched(OrderChargesChangedBroadcast::class);
    });

    Event::assertDispatched(
        OrderChargesChangedBroadcast::class,
        fn (OrderChargesChangedBroadcast $event) => $event->order->is($order)
            && $event->kind === 'fees'
            && $event->broadcastAs() === 'order.charges_changed'
    );
});

it('never broadcasts charge changes from a rolled-back transaction', function () {
    Event::fake([OrderChargesChangedBroadcast::class]);
    $order = chargedOrder();

    try {
        DB::transaction(function () use ($order) {
            OrderChargesChanged::dispatch($order, OrderChargesChanged::EQUIPMENT);
            throw new RuntimeException('fail');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(OrderChargesChangedBroadcast::class);
});
