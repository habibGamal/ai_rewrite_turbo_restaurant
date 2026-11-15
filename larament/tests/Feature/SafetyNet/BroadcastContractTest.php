<?php

/*
 * Phase 0 safety net: the broadcast names and channels the POS screens listen to.
 *
 * Phase 1 changes *when* these broadcasts go out (after commit) and Phase 3
 * moves who dispatches them. Neither should change *what* the browser receives,
 * so the name, channels and payload keys of each one are pinned here.
 */

use App\Events\Orders\OrderCancelled;
use App\Events\Orders\OrderCompleted;
use App\Events\Orders\OrderCreated;
use App\Events\Orders\OrderTypeChanged;
use App\Events\Orders\PaymentProcessed;
use App\Events\Orders\WebOrderReceived;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Orders\OrderService;
use Illuminate\Support\Facades\Event;
use Tests\Support\SafetyNet;

function safetyNetChannels(object $event): array
{
    return array_map(fn ($channel): string => $channel->name, $event->broadcastOn());
}

it('broadcasts the cashier lifecycle on the shift and kitchen channels', function () {
    ['user' => $user, 'shift' => $shift] = SafetyNet::till();
    Event::fake([OrderCreated::class, OrderCompleted::class, OrderCancelled::class, PaymentProcessed::class, OrderTypeChanged::class]);
    $orders = app(OrderService::class);

    $order = SafetyNet::open($shift, $user);
    SafetyNet::save($order, [[SafetyNet::product(price: 40), 1]]);
    $orders->changeOrderType($order->id, 'delivery');
    $orders->completeOrder($order->id, ['cash' => 40]);
    $orders->cancelOrder($order->id, 'mistake');

    $shiftChannel = 'private-shift.'.$shift->id;
    $expected = [
        OrderCreated::class => ['order.created', [$shiftChannel, 'private-kitchen'], ['order']],
        OrderTypeChanged::class => ['order.type_changed', [$shiftChannel], null],
        PaymentProcessed::class => ['payment.processed', [$shiftChannel], ['payment']],
        OrderCompleted::class => ['order.completed', [$shiftChannel, 'private-kitchen'], ['order']],
        OrderCancelled::class => ['order.cancelled', [$shiftChannel, 'private-kitchen'], ['order']],
    ];

    foreach ($expected as $class => [$name, $channels, $keys]) {
        Event::assertDispatchedTimes($class, 1);
        $event = Event::dispatched($class)->first()[0];
        expect($event->broadcastAs())->toBe($name)
            ->and(safetyNetChannels($event))->toBe($channels);
        if ($keys !== null) {
            expect(array_keys($event->broadcastWith()))->toBe($keys);
        }
    }

    $created = Event::dispatched(OrderCreated::class)->first()[0]->broadcastWith()['order'];
    expect($created['id'])->toBe($order->id)
        ->and($created['order_number'])->toEqual($order->order_number);
    expect(Event::dispatched(OrderCancelled::class)->first()[0]->broadcastWith()['order']['reason'])->toBe('mistake');
});

it('broadcasts a received web order on the web-orders channel', function () {
    $event = new WebOrderReceived(Order::factory()->create([
        'type' => 'web_delivery',
        'customer_id' => Customer::factory()->create()->id,
    ]));

    expect($event->broadcastAs())->toBe('web-order.received')
        ->and(safetyNetChannels($event))->toBe(['private-web-orders'])
        ->and(array_keys($event->broadcastWith()))->toBe(['order']);
});
