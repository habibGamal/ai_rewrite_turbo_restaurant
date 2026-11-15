<?php

/*
 * Phase 3: the order flow talks to modules only through domain events and
 * core extension points. These tests pin what changes when a module is off
 * and that in-transaction listeners can still stop a sale.
 */

use App\Core\Events\OrderCancelled;
use App\Core\Events\OrderCompleted;
use App\Core\Events\OrderCompleting;
use App\Core\Events\OrderItemsChanged;
use App\Core\Events\OrderOpened;
use App\Core\Events\OrderReturned;
use App\Core\Events\OrderTypeChanged;
use App\Core\Events\PaymentRecorded;
use App\Enums\OrderStatus;
use App\Enums\SettingKey;
use App\Events\Orders\OrderCancelledBroadcast;
use App\Events\Orders\OrderCompletedBroadcast;
use App\Exceptions\OrderException;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\Setting;
use App\Modules\Module;
use App\Modules\WebOrders\Services\WebApiService;
use App\Services\Orders\OrderReturnService;
use App\Services\Orders\OrderService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\SafetyNet;

beforeEach(function () {
    ['user' => $this->user, 'shift' => $this->shift] = SafetyNet::till();
    $this->orders = app(OrderService::class);
});

function switchModulesTo(Module ...$modules): void
{
    Setting::updateOrCreate(
        ['key' => SettingKey::ENABLED_MODULES->value],
        ['value' => json_encode(array_map(fn (Module $m): string => $m->value, $modules))],
    );
}

it('dispatches each domain event once at its point in the cashier flow', function () {
    $seen = [];
    foreach ([OrderOpened::class, OrderItemsChanged::class, OrderTypeChanged::class, OrderCompleting::class, OrderCompleted::class, PaymentRecorded::class, OrderCancelled::class, OrderReturned::class] as $event) {
        Event::listen($event, function () use (&$seen, $event): void {
            $seen[] = class_basename($event);
        });
    }

    $burger = SafetyNet::product(price: 100, stock: 10);
    $order = SafetyNet::open($this->shift, $this->user);
    SafetyNet::save($order, [[$burger, 1]]);
    $this->orders->changeOrderType($order->id, 'dine_in', 'T1');
    $total = (float) $order->fresh()->total;
    $this->orders->completeOrder($order->id, ['cash' => 50, 'card' => $total - 50]);

    expect($seen)->toBe(['OrderOpened', 'OrderItemsChanged', 'OrderTypeChanged', 'PaymentRecorded', 'PaymentRecorded', 'OrderCompleting', 'OrderCompleted']);

    $seen = [];
    $other = SafetyNet::open($this->shift, $this->user);
    $this->orders->cancelOrder($other->id, 'test');
    expect($seen)->toBe(['OrderOpened', 'OrderCancelled']);
});

it('passes the was-completed flag and reason on cancel', function () {
    $captured = null;
    Event::listen(OrderCancelled::class, function (OrderCancelled $event) use (&$captured): void {
        $captured = $event;
    });
    $order = SafetyNet::open($this->shift, $this->user);
    SafetyNet::save($order, [[SafetyNet::product(price: 40), 1]]);
    $this->orders->completeOrder($order->id, ['cash' => 40]);

    $this->orders->cancelOrder($order->id, 'wrong order');

    expect($captured->wasCompleted)->toBeTrue()
        ->and($captured->reason)->toBe('wrong order')
        ->and($captured->order->id)->toBe($order->id);
});

it('dispatches OrderReturned inside the return', function () {
    $returned = null;
    Event::listen(OrderReturned::class, function (OrderReturned $event) use (&$returned): void {
        $returned = $event;
    });
    $this->actingAs($this->user);
    $order = SafetyNet::open($this->shift, $this->user);
    SafetyNet::save($order, [[SafetyNet::product(price: 40, stock: 5), 1]]);
    $completed = $this->orders->completeOrder($order->id, ['cash' => 40]);
    $item = $completed->items()->firstOrFail();

    $return = app(OrderReturnService::class)->processReturn(
        $completed,
        [['order_item_id' => $item->id, 'quantity' => 1, 'refund_amount' => 40]],
        'broken',
        [['method' => 'cash', 'amount' => 40]],
        $this->shift->id,
    );

    expect($returned)->not->toBeNull()
        ->and($returned->orderReturn->is($return))->toBeTrue();
});

it('lets an OrderCompleting listener veto the sale and rolls back payments and stock', function () {
    Event::fake([OrderCompletedBroadcast::class]);
    Event::listen(OrderCompleting::class, function (): void {
        throw new OrderException('veto');
    });
    $burger = SafetyNet::product(price: 100, stock: 10);
    $order = SafetyNet::open($this->shift, $this->user);
    SafetyNet::save($order, [[$burger, 1]]);

    expect(fn () => $this->orders->completeOrder($order->id, ['cash' => 100]))
        ->toThrow(OrderException::class, 'veto');

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::PROCESSING)
        ->and($order->payments()->count())->toBe(0)
        ->and(SafetyNet::stock($burger))->toBe(10.0);
    Event::assertNotDispatched(OrderCompletedBroadcast::class);
});

it('never announces a cancel that rolled back', function () {
    Event::fake([OrderCancelledBroadcast::class]);
    Event::listen(OrderCancelled::class, function (): void {
        throw new OrderException('no');
    });
    $order = SafetyNet::open($this->shift, $this->user);

    expect(fn () => $this->orders->cancelOrder($order->id))->toThrow(OrderException::class);

    expect($order->fresh()->status)->toBe(OrderStatus::PROCESSING);
    Event::assertNotDispatched(OrderCancelledBroadcast::class);
});

describe('with the Restaurant module off', function () {
    beforeEach(fn () => switchModulesTo(Module::DELIVERY));

    it('saves, completes and cancels cashier orders without touching the kitchen', function () {
        $burger = SafetyNet::product(price: 100, stock: 10);
        $order = SafetyNet::open($this->shift, $this->user, 'dine_in', 'T1');
        SafetyNet::save($order, [[$burger, 2]]);
        $this->orders->changeOrderType($order->id, 'takeaway');
        $completed = $this->orders->completeOrder($order->id, ['cash' => 200]);

        $other = SafetyNet::open($this->shift, $this->user);
        SafetyNet::save($other, [[$burger, 1]]);
        $this->orders->cancelOrder($other->id);

        expect($completed->status)->toBe(OrderStatus::COMPLETED)
            ->and(SafetyNet::stock($burger))->toBe(8.0)
            ->and($other->fresh()->status)->toBe(OrderStatus::CANCELLED)
            ->and(KitchenTicket::count())->toBe(0);
    });

    it('sends accepted web orders past the kitchen', function () {
        app(SettingsService::class)->set(SettingKey::WEBSITE_URL->value, 'https://site.test');
        Http::fake(['*' => Http::response(['ok' => true])]);
        SafetyNet::product(price: 80, cost: 30, stock: 20, attributes: ['product_ref' => 'BURGER']);
        $web = app(WebApiService::class);

        $web->placeOrder([
            'user' => ['name' => 'Web Guest', 'phone' => '01011112222', 'area' => 'Nasr City', 'address' => '12 Street'],
            'order' => [
                'type' => 'web_delivery', 'shiftId' => $this->shift->id, 'orderNumber' => 'W-1',
                'subTotal' => 80, 'tax' => 0, 'service' => 0, 'discount' => 0, 'total' => 80,
                'items' => [['quantity' => 1, 'notes' => '', 'posRefObj' => [['productRef' => 'BURGER', 'quantity' => 1]]]],
            ],
        ]);
        $order = Order::sole();
        $web->acceptOrder($order->id);
        $web->completeOrder($order->id);

        expect($order->fresh()->status)->toBe(OrderStatus::COMPLETED)
            ->and(KitchenTicket::count())->toBe(0);
    });
});

it('still writes kitchen tickets with the Restaurant module on', function () {
    switchModulesTo(Module::RESTAURANT);
    $order = SafetyNet::open($this->shift, $this->user);
    SafetyNet::save($order, [[SafetyNet::product(price: 10), 1]]);

    expect(KitchenTicket::where('order_id', $order->id)->exists())->toBeTrue();
});
