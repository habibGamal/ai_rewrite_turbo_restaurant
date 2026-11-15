<?php

use App\Enums\OrderStatus;
use App\Events\Orders\OrderCancelledBroadcast;
use App\Events\Orders\OrderCompletedBroadcast;
use App\Events\Orders\OrderCreatedBroadcast;
use App\Events\Orders\OrderTypeChangedBroadcast;
use App\Events\Orders\PaymentProcessedBroadcast;
use App\Events\Orders\WebOrderReceived;
use App\Models\Order;
use App\Modules\Restaurant\Events\KitchenTicketUpdated;
use App\Services\Orders\OrderService;
use App\Services\WebApiService;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['broadcasting.default' => 'null']);
});

it('defers every order broadcast event until the transaction commits', function (string $event) {
    expect(is_subclass_of($event, ShouldDispatchAfterCommit::class))->toBeTrue();
})->with([
    OrderCreatedBroadcast::class,
    OrderCompletedBroadcast::class,
    OrderCancelledBroadcast::class,
    PaymentProcessedBroadcast::class,
    WebOrderReceived::class,
    OrderTypeChangedBroadcast::class,
    KitchenTicketUpdated::class,
]);

it('delivers OrderCreatedBroadcast only after commit and never after a rollback', function () {
    $delivered = [];
    Event::listen(OrderCreatedBroadcast::class, function (OrderCreatedBroadcast $event) use (&$delivered): void {
        $delivered[] = $event->order->id;
    });

    $order = Order::factory()->create();

    DB::transaction(function () use ($order, &$delivered): void {
        OrderCreatedBroadcast::dispatch($order);
        expect($delivered)->toBe([]);
    });
    expect($delivered)->toBe([$order->id]);

    $delivered = [];
    try {
        DB::transaction(function () use ($order): void {
            OrderCreatedBroadcast::dispatch($order);
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // rolled back on purpose
    }
    expect($delivered)->toBe([]);
});

it('does not announce a cancellation that is rolled back by the caller', function () {
    Http::fake();
    $delivered = 0;
    Event::listen(OrderCancelledBroadcast::class, function () use (&$delivered): void {
        $delivered++;
    });

    $order = Order::factory()->create([
        'status' => OrderStatus::PROCESSING,
        'type' => 'takeaway',
        'dine_table_number' => null,
    ]);

    try {
        DB::transaction(function () use ($order): void {
            app(OrderService::class)->cancelOrder($order->id);
            throw new RuntimeException('caller failed after cancelling');
        });
    } catch (RuntimeException) {
        // rolled back on purpose
    }

    expect($delivered)->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::PROCESSING);

    app(OrderService::class)->cancelOrder($order->id);

    expect($delivered)->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::CANCELLED);
});

it('accepts a web order and notifies the website in one step', function () {
    Http::fake();
    $order = Order::factory()->create([
        'status' => OrderStatus::PENDING,
        'type' => 'web_delivery',
    ]);

    app(WebApiService::class)->acceptOrder($order->id);

    expect($order->fresh()->status)->toBe(OrderStatus::PROCESSING);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/order-status')
        && $request['status'] === OrderStatus::PROCESSING->value);
});

it('rolls a web order status change back when the website notification fails', function (string $method, OrderStatus $from) {
    Http::fake(['*' => Http::response('down', 500)]);
    $order = Order::factory()->create([
        'status' => $from,
        'type' => 'web_delivery',
    ]);

    expect(fn () => app(WebApiService::class)->{$method}($order->id))->toThrow(Exception::class);

    expect($order->fresh()->status)->toBe($from);
})->with([
    'accept' => ['acceptOrder', OrderStatus::PENDING],
    'reject' => ['rejectOrder', OrderStatus::PENDING],
    'out for delivery' => ['outForDelivery', OrderStatus::PROCESSING],
    'complete' => ['completeOrder', OrderStatus::OUT_FOR_DELIVERY],
]);
