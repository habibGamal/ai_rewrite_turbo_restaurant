<?php

use App\Enums\KitchenItemStatus;
use App\Enums\KitchenTicketStatus;
use App\Enums\OrderStatus;
use App\Enums\SettingKey;
use App\Modules\Restaurant\Events\KitchenTicketUpdated;
use App\Models\KitchenStation;
use App\Models\KitchenTicket;
use App\Models\KitchenTicketItem;
use App\Models\KitchenTicketRound;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shift;

beforeEach(function () {
    Setting::setValue(SettingKey::KDS_CASHIER_STATUS_ENABLED->value, 'true');
});

it('returns in_progress when kitchen ticket is active and ready when done', function () {
    $shift = Shift::factory()->create();
    $order = Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => OrderStatus::PROCESSING,
    ]);

    $ticket = KitchenTicket::factory()->create([
        'order_id' => $order->id,
        'status' => KitchenTicketStatus::ACTIVE,
    ]);

    $order->refresh();
    expect($order->getKitchenStatus())->toBe('in_progress');

    $ticket->status = KitchenTicketStatus::DONE;
    $ticket->save();

    $order->refresh();
    expect($order->getKitchenStatus())->toBe('ready');
});

it('resolves per-item kitchen status accurately', function () {
    $shift = Shift::factory()->create();
    $order = Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => OrderStatus::PROCESSING,
    ]);

    $station = KitchenStation::factory()->create();
    $product1 = Product::factory()->create(['kitchen_station_id' => $station->id]);
    $product2 = Product::factory()->create(['kitchen_station_id' => $station->id]);
    $product3 = Product::factory()->create(['show_on_kds' => false]);

    $orderItem1 = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product1->id,
    ]);
    $orderItem2 = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product2->id,
    ]);
    $orderItem3 = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product3->id,
    ]);

    $ticket = KitchenTicket::factory()->create([
        'order_id' => $order->id,
        'status' => KitchenTicketStatus::ACTIVE,
    ]);
    $round = KitchenTicketRound::factory()->create([
        'kitchen_ticket_id' => $ticket->id,
    ]);

    KitchenTicketItem::factory()->create([
        'kitchen_ticket_id' => $ticket->id,
        'kitchen_ticket_round_id' => $round->id,
        'product_id' => $product1->id,
        'status' => KitchenItemStatus::DONE,
    ]);

    KitchenTicketItem::factory()->create([
        'kitchen_ticket_id' => $ticket->id,
        'kitchen_ticket_round_id' => $round->id,
        'product_id' => $product2->id,
        'status' => KitchenItemStatus::PENDING,
    ]);

    $order->load(['kitchenTicket.items']);

    expect($order->getItemKitchenStatus($product1->id))->toBe('ready')
        ->and($order->getItemKitchenStatus($product2->id))->toBe('in_progress')
        ->and($order->getItemKitchenStatus($product3->id))->toBeNull()
        ->and($order->getKitchenProgress())->toBe([
            'done' => 1,
            'total' => 2,
            'status' => 'in_progress',
        ]);
});

it('returns null for kitchen status when setting is disabled', function () {
    Setting::setValue(SettingKey::KDS_CASHIER_STATUS_ENABLED->value, 'false');

    $shift = Shift::factory()->create();
    $order = Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => OrderStatus::PROCESSING,
    ]);

    $ticket = KitchenTicket::factory()->create([
        'order_id' => $order->id,
        'status' => KitchenTicketStatus::DONE,
    ]);

    $order->refresh();
    expect($order->getKitchenStatus())->toBeNull()
        ->and($order->getItemKitchenStatus(123))->toBeNull();
});

it('broadcasts KitchenTicketUpdated on shift channel when shift_id is present', function () {
    $shift = Shift::factory()->create();
    $order = Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => OrderStatus::PROCESSING,
    ]);

    $ticket = KitchenTicket::factory()->create([
        'order_id' => $order->id,
        'status' => KitchenTicketStatus::DONE,
    ]);

    $event = new KitchenTicketUpdated(
        $ticket->id,
        (int) $order->order_number,
        KitchenTicketUpdated::REASON_DONE,
        [1],
        $shift->id,
    );

    $channels = $event->broadcastOn();
    expect($channels)->toHaveCount(2)
        ->and($channels[0]->name)->toBe('private-kitchen')
        ->and($channels[1]->name)->toBe('private-shift.'.$shift->id);
});
