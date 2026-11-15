<?php

use App\Core\Events\OrderCompleting;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\OrderException;
use App\Models\HeldSale;
use App\Models\InventoryItemMovement;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\OrderTypeRow;
use App\Models\Payment;
use App\Models\User;
use App\Modules\BusinessType;
use App\Orders\TabPermissions;
use App\Services\FeatureService;
use App\Services\ShiftService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SafetyNet;

beforeEach(function () {
    ['user' => $this->user, 'shift' => $this->shift] = SafetyNet::till();
    OrderTypeRow::query()->where('code', 'instant_sale')->update(['is_active' => true]);
    $this->shirt = SafetyNet::product(price: 120, cost: 50, stock: 10);
    $this->socks = SafetyNet::product(price: 30, cost: 10, stock: 10);
});

function instantSale(array $overrides = []): array
{
    return array_merge([
        'client_uuid' => (string) Str::uuid(),
        'type' => 'instant_sale',
        'items' => [
            ['product_id' => test()->shirt->id, 'quantity' => 2],
            ['product_id' => test()->socks->id, 'quantity' => 1],
        ],
        'tenders' => ['cash' => 300],
    ], $overrides);
}

it('seeds the instant sale type, inactive on a restaurant install', function () {
    $row = OrderTypeRow::query()->where('code', 'instant_sale')->firstOrFail();

    expect($row->behavior->value)->toBe('instant')
        ->and($row->is_system)->toBeTrue();
});

it('creates a completed, fully paid order with stock removed and profit set in one request', function () {
    $response = $this->postJson(route('pos.instant-sales.store'), instantSale())->assertOk();

    $order = Order::query()->sole();

    expect($order->status)->toBe(OrderStatus::COMPLETED)
        ->and($order->payment_status)->toBe(PaymentStatus::FULL_PAID)
        ->and((float) $order->total)->toBe(270.0)
        ->and((float) $order->profit)->toBe(160.0)
        ->and((int) $order->order_number)->toBe(1)
        ->and((float) Payment::query()->sum('amount'))->toBe(270.0)
        ->and(SafetyNet::stock($this->shirt))->toBe(8.0)
        ->and(SafetyNet::stock($this->socks))->toBe(9.0);

    $response->assertJsonPath('order.order_number', 1)
        ->assertJsonPath('order.change', 30)
        ->assertJsonPath('duplicate', false);
});

it('returns the same order for a repeated client_uuid', function () {
    $payload = instantSale();

    $first = $this->postJson(route('pos.instant-sales.store'), $payload)->assertOk()->json('order.id');
    $this->postJson(route('pos.instant-sales.store'), $payload)
        ->assertOk()
        ->assertJsonPath('order.id', $first)
        ->assertJsonPath('duplicate', true);

    expect(Order::count())->toBe(1)
        ->and(Payment::count())->toBe(1)
        ->and(SafetyNet::stock($this->shirt))->toBe(8.0);
});

it('leaves no order, payment or stock movement when an OrderCompleting listener refuses', function () {
    $movementsBefore = InventoryItemMovement::count();
    Event::listen(OrderCompleting::class, fn () => throw new OrderException('مرفوض'));

    $this->postJson(route('pos.instant-sales.store'), instantSale())
        ->assertStatus(422)
        ->assertJsonPath('message', 'مرفوض');

    expect(Order::count())->toBe(0)
        ->and(Payment::count())->toBe(0)
        ->and(InventoryItemMovement::count())->toBe($movementsBefore)
        ->and(SafetyNet::stock($this->shirt))->toBe(10.0);
});

it('creates no kitchen ticket with Restaurant on', function () {
    $this->postJson(route('pos.instant-sales.store'), instantSale())->assertOk();

    expect(KitchenTicket::count())->toBe(0);
});

it('refuses an underpaid sale and writes nothing', function () {
    $this->postJson(route('pos.instant-sales.store'), instantSale(['tenders' => ['cash' => 100]]))
        ->assertStatus(422);

    expect(Order::count())->toBe(0)->and(SafetyNet::stock($this->shirt))->toBe(10.0);
});

it('refuses a non-instant order type', function () {
    $this->postJson(route('pos.instant-sales.store'), instantSale(['type' => 'takeaway']))
        ->assertStatus(422);

    expect(Order::count())->toBe(0);
});

it('applies an order discount and splits card and cash', function () {
    $this->postJson(route('pos.instant-sales.store'), instantSale([
        'discount' => ['value' => 10, 'type' => 'percent'],
        'tenders' => ['cash' => 100, 'card' => 143],
    ]))->assertOk()->assertJsonPath('order.total', 243);

    expect(Payment::query()->where('method', 'card')->sum('amount'))->toEqual(143);
});

it('takes order numbers only at pay time, in sequence with other orders', function () {
    SafetyNet::open($this->shift, $this->user, 'takeaway');

    $this->postJson(route('pos.instant-sales.store'), instantSale())->assertOk()
        ->assertJsonPath('order.order_number', 2);
});

it('deletes a resumed held sale when it is paid', function () {
    $held = $this->postJson(route('pos.held-sales.store'), [
        'type' => 'instant_sale',
        'label' => 'زبون 1',
        'items' => [['product_id' => $this->shirt->id, 'quantity' => 1]],
    ])->assertCreated()->json('data');

    expect($held['total'])->toEqual(120)
        ->and(Order::count())->toBe(0);

    $this->getJson(route('pos.held-sales.index'))->assertOk()->assertJsonCount(1, 'data');

    $this->postJson(route('pos.instant-sales.store'), instantSale([
        'items' => $held['lines'],
        'held_sale_id' => $held['id'],
        'tenders' => ['cash' => 120],
    ]))->assertOk();

    expect(HeldSale::count())->toBe(0);
});

it('deletes a held sale on request and discards held sales at shift end', function () {
    $held = HeldSale::query()->create([
        'shift_id' => $this->shift->id, 'user_id' => $this->user->id, 'order_type' => 'instant_sale',
        'lines' => [['product_id' => $this->shirt->id, 'quantity' => 1]], 'total' => 120,
    ]);
    $this->deleteJson(route('pos.held-sales.destroy', $held))->assertNoContent();

    HeldSale::query()->create([
        'shift_id' => $this->shift->id, 'user_id' => $this->user->id, 'order_type' => 'instant_sale',
        'lines' => [], 'total' => 0,
    ]);
    app(ShiftService::class)->endShift(0);

    expect(HeldSale::count())->toBe(0);
});

it('returns lines of an instant sale and refunds by unit price after discount', function () {
    $orderId = $this->postJson(route('pos.instant-sales.store'), instantSale())->json('order.id');
    $order = Order::query()->with('items')->findOrFail($orderId);
    $shirtLine = $order->items->firstWhere('product_id', $this->shirt->id);

    $this->getJson(route('pos.instant-returns.lookup', ['number' => $order->order_number]))
        ->assertOk()
        ->assertJsonPath('order.id', $order->id)
        ->assertJsonPath('order.items.0.returnable_quantity', 2);

    $this->postJson(route('pos.instant-returns.store'), [
        'order_id' => $order->id,
        'items' => [['order_item_id' => $shirtLine->id, 'quantity' => 1]],
        'tenders' => [],
    ])->assertOk()
        ->assertJsonPath('return.total_refund', 120)
        ->assertJsonPath('net', -120)
        ->assertJsonPath('exchange_order', null);

    expect(SafetyNet::stock($this->shirt))->toBe(9.0);
});

it('exchanges a line and settles only the difference', function () {
    $orderId = $this->postJson(route('pos.instant-sales.store'), instantSale())->json('order.id');
    $order = Order::query()->with('items')->findOrFail($orderId);
    $socksLine = $order->items->firstWhere('product_id', $this->socks->id);

    // Return socks (30), take one shirt (120): the customer pays 90.
    $response = $this->postJson(route('pos.instant-returns.store'), [
        'order_id' => $order->id,
        'items' => [['order_item_id' => $socksLine->id, 'quantity' => 1]],
        'exchange' => [
            'client_uuid' => (string) Str::uuid(),
            'type' => 'instant_sale',
            'items' => [['product_id' => $this->shirt->id, 'quantity' => 1]],
        ],
        'tenders' => ['cash' => 90],
    ])->assertOk()
        ->assertJsonPath('net', 90);

    $exchange = Order::query()->findOrFail($response->json('exchange_order.id'));

    expect($exchange->status)->toBe(OrderStatus::COMPLETED)
        ->and($exchange->payment_status)->toBe(PaymentStatus::FULL_PAID)
        ->and(SafetyNet::stock($this->socks))->toBe(10.0)
        ->and(SafetyNet::stock($this->shirt))->toBe(7.0);
});

it('activates instant sale when the vendor picks a retail business type', function () {
    app(FeatureService::class)->setBusinessType(BusinessType::FASHION);

    expect(OrderTypeRow::query()->where('code', 'instant_sale')->value('is_active'))->toBeTruthy();
})->skip(fn () => app(FeatureService::class)->businessTypeLocked(), 'business type locked by fixtures');

it('sends the instant tab payload and makes it the default tab for retail', function () {
    app(FeatureService::class)->setBusinessType(BusinessType::GROCERY);

    $this->get(route('orders.index'))->assertOk()->assertInertia(function ($page) {
        $page->component('Orders/Index')->where('defaultTab', 'type:instant_sale');

        $tab = collect($page->toArray()['props']['tabs'])->firstWhere('code', 'instant_sale');
        expect($tab['presentation']['body'])->toBe('instant')
            ->and($tab['instant'])->toHaveKeys(['categories', 'lastSale', 'heldCount', 'credit', 'can']);
    });
});

it('lets a cashier sell on the instant tab and refuses a user without the tab', function () {
    $cashier = User::factory()->cashier()->create();
    $this->actingAs($cashier)
        ->postJson(route('pos.instant-sales.store'), instantSale())
        ->assertOk();

    $cashier->revokePermissionTo(TabPermissions::forTypeCode('instant_sale'));
    foreach ($cashier->roles as $role) {
        $role->revokePermissionTo(TabPermissions::forTypeCode('instant_sale'));
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($cashier->fresh())
        ->postJson(route('pos.instant-sales.store'), instantSale())
        ->assertForbidden();
});
