<?php

/*
 * Phase 0 safety net: web orders from the website API to completion.
 *
 * Pins today's contract that the Web orders module must keep: the API places a
 * PENDING order with server-priced items, the kitchen sees it only once it is
 * accepted, every status change is pushed to the website, and reject/complete
 * go through the same OrderService paths as cashier orders.
 */

use App\Enums\KitchenTicketStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\SettingKey;
use App\Models\Customer;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Modules\WebOrders\Events\WebOrderReceived;
use App\Services\SettingsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\SafetyNet;

beforeEach(function () {
    ['user' => $this->user, 'shift' => $this->shift] = SafetyNet::till();
    config(['app.web_api_key' => 'safety-net-key']);
    app(SettingsService::class)->set(SettingKey::WEBSITE_URL->value, 'https://site.test');
    $this->siteUp = true;
    Http::fake(fn () => $this->siteUp ? Http::response(['ok' => true]) : Http::response('down', 500));

    $this->burger = SafetyNet::product(price: 80, cost: 30, stock: 20, attributes: ['product_ref' => 'BURGER']);
    $this->fries = SafetyNet::product(price: 20, cost: 5, stock: 20, attributes: ['product_ref' => 'FRIES']);
});

function safetyNetWebOrder(int $shiftId, array $overrides = []): array
{
    return array_replace_recursive([
        'user' => ['name' => 'Web Guest', 'phone' => '01011112222', 'area' => 'Nasr City', 'address' => '12 Street'],
        'order' => [
            'type' => 'web_delivery',
            'shiftId' => $shiftId,
            'orderNumber' => 'W-1001',
            'subTotal' => 200,
            'tax' => 0,
            'service' => 15,
            'discount' => 0,
            'total' => 210,
            'note' => 'ring twice',
            'items' => [
                // One combo = 1 burger + 2 fries, ordered twice.
                ['quantity' => 2, 'notes' => 'no onion', 'posRefObj' => [
                    ['productRef' => 'BURGER', 'quantity' => 1],
                    ['productRef' => 'FRIES', 'quantity' => 1],
                ]],
            ],
        ],
    ], $overrides);
}

function safetyNetPlaceWebOrder(array $payload): TestResponse
{
    return test()->withHeaders(['X-Api-Key' => 'safety-net-key'])->postJson('/api/web-orders/place-order', $payload);
}

it('places a pending web order with server prices and no kitchen ticket', function () {
    Event::fake([WebOrderReceived::class]);

    safetyNetPlaceWebOrder(safetyNetWebOrder($this->shift->id))->assertOk();

    $order = Order::with('items', 'customer')->sole();
    expect($order->status)->toBe(OrderStatus::PENDING)
        ->and($order->type)->toBe('web_delivery')
        ->and($order->order_number)->toBe('W-1001')
        ->and($order->shift_id)->toBe($this->shift->id)
        ->and($order->payment_status)->toBe(PaymentStatus::PENDING)
        ->and($order->order_notes)->toBe('ring twice')
        ->and($order->items)->toHaveCount(2)
        ->and($order->items->firstWhere('product_id', $this->burger->id)->quantity)->toEqual(2)
        ->and((float) $order->items->firstWhere('product_id', $this->burger->id)->price)->toBe(80.0)
        ->and($order->items->firstWhere('product_id', $this->fries->id)->notes)->toBe('no onion')
        // POS sub_total is recomputed (2*80 + 2*20 = 200); the website total wins and the gap is stored.
        ->and((float) $order->sub_total)->toBe(200.0)
        ->and((float) $order->service)->toBe(15.0)
        ->and((float) $order->total)->toBe(210.0)
        ->and((float) $order->discount)->toBe(5.0)
        ->and((float) $order->web_pos_diff)->toBe(-5.0)
        ->and((float) $order->profit)->toBe(210.0 - (2 * 30 + 2 * 5))
        ->and($order->customer->phone)->toBe('01011112222')
        ->and((float) $order->customer->delivery_cost)->toBe(15.0)
        ->and(KitchenTicket::count())->toBe(0)
        ->and(SafetyNet::stock($this->burger))->toBe(20.0);

    Event::assertDispatchedTimes(WebOrderReceived::class, 1);
    Http::assertNothingSent();
});

it('reuses the customer by phone and updates the address', function () {
    Customer::factory()->create(['phone' => '01011112222', 'name' => 'Old Name', 'address' => 'Old']);

    safetyNetPlaceWebOrder(safetyNetWebOrder($this->shift->id))->assertOk();

    expect(Customer::where('phone', '01011112222')->count())->toBe(1)
        ->and(Customer::where('phone', '01011112222')->value('address'))->toBe('12 Street')
        ->and(Customer::where('phone', '01011112222')->value('name'))->toBe('Old Name');
});

it('rejects a web order for a shift that is not the open one and saves nothing', function () {
    safetyNetPlaceWebOrder(safetyNetWebOrder($this->shift->id + 99))->assertStatus(400);

    expect(Order::count())->toBe(0);
});

it('rejects a web order with an unknown product ref and saves nothing', function () {
    $payload = safetyNetWebOrder($this->shift->id);
    $payload['order']['items'][0]['posRefObj'][0]['productRef'] = 'NOPE';

    safetyNetPlaceWebOrder($payload)->assertStatus(400);

    expect(Order::count())->toBe(0)
        ->and(Customer::count())->toBe(0);
});

it('refuses a cashier order type through the web api', function () {
    safetyNetPlaceWebOrder(safetyNetWebOrder($this->shift->id, ['order' => ['type' => 'takeaway']]))->assertStatus(422);

    expect(Order::count())->toBe(0);
});

it('accepts, sends out and completes a web order, telling the website each time', function () {
    safetyNetPlaceWebOrder(safetyNetWebOrder($this->shift->id))->assertOk();
    $order = Order::sole();
    $this->actingAs($this->user);

    $this->post(route('web-orders.accept', $order))->assertRedirect()->assertSessionHas('success');
    $order->refresh();
    expect($order->status)->toBe(OrderStatus::PROCESSING)
        ->and($order->kitchenTicket->status)->toBe(KitchenTicketStatus::ACTIVE)
        ->and($order->kitchenTicket->items)->toHaveCount(2);

    $this->post(route('web-orders.outForDelivery', $order))->assertRedirect()->assertSessionHas('success');
    expect($order->fresh()->status)->toBe(OrderStatus::OUT_FOR_DELIVERY);

    $this->post(route('web-orders.complete', $order), ['cash' => 210])->assertRedirect()->assertSessionHas('success');
    $order->refresh();
    expect($order->status)->toBe(OrderStatus::COMPLETED)
        ->and($order->payment_status)->toBe(PaymentStatus::FULL_PAID)
        ->and((float) $order->payments()->sum('amount'))->toBe(210.0)
        ->and(SafetyNet::stock($this->burger))->toBe(18.0)
        ->and(SafetyNet::stock($this->fries))->toBe(18.0)
        ->and($order->kitchenTicket->fresh()->status)->toBe(KitchenTicketStatus::DONE);

    $sent = collect(Http::recorded())->map(fn (array $pair): array => [
        $pair[0]->url(),
        $pair[0]['orderNumber'],
        $pair[0]['status'],
    ])->all();
    expect($sent)->toBe([
        ['https://site.test/api/order-status', 'W-1001', 'processing'],
        ['https://site.test/api/order-status', 'W-1001', 'out_for_delivery'],
        ['https://site.test/api/order-status', 'W-1001', 'completed'],
    ]);
});

it('rejects a web order: cancels it, cancels the kitchen ticket and tells the website', function () {
    safetyNetPlaceWebOrder(safetyNetWebOrder($this->shift->id))->assertOk();
    $order = Order::sole();
    $this->actingAs($this->user);
    $this->post(route('web-orders.accept', $order))->assertRedirect();

    $this->post(route('web-orders.reject', $order))->assertRedirect()->assertSessionHas('success');

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::CANCELLED)
        ->and($order->kitchenTicket->status)->toBe(KitchenTicketStatus::CANCELLED)
        ->and(SafetyNet::stock($this->burger))->toBe(20.0);
    Http::assertSent(fn (Request $request): bool => $request['status'] === 'cancelled');
});

it('rolls back an accept when the website does not answer', function () {
    $this->siteUp = false;
    safetyNetPlaceWebOrder(safetyNetWebOrder($this->shift->id))->assertOk();
    $order = Order::sole();
    $this->actingAs($this->user);

    $this->post(route('web-orders.accept', $order))->assertRedirect()->assertSessionHasErrors();

    expect($order->fresh()->status)->toBe(OrderStatus::PENDING)
        ->and(KitchenTicket::count())->toBe(0);
});

it('rolls back a completion when the website does not answer', function () {
    safetyNetPlaceWebOrder(safetyNetWebOrder($this->shift->id))->assertOk();
    $order = Order::sole();
    $this->actingAs($this->user);
    $this->post(route('web-orders.accept', $order))->assertRedirect();
    $this->siteUp = false;

    $this->post(route('web-orders.complete', $order), ['cash' => 210])->assertRedirect()->assertSessionHas('error');

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::PROCESSING)
        ->and($order->payments()->count())->toBe(0)
        ->and(SafetyNet::stock($this->burger))->toBe(20.0)
        ->and($order->kitchenTicket->status)->toBe(KitchenTicketStatus::ACTIVE);
});
