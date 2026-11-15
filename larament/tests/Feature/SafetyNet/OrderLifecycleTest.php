<?php

/*
 * Phase 0 safety net: the cashier order lifecycle as it behaves today.
 *
 * Each test drives OrderService (the seam the modular-core refactor keeps) and
 * pins every side effect a later phase could move: table reservation, kitchen
 * tickets, payments, stock, profit, broadcasts and shift logs.
 */

use App\Enums\KitchenItemStatus;
use App\Enums\KitchenTicketStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\SettingKey;
use App\Events\Kitchen\KitchenTicketUpdated;
use App\Events\Orders\OrderCancelled;
use App\Events\Orders\OrderCompleted;
use App\Events\Orders\OrderCreated;
use App\Events\Orders\OrderTypeChanged;
use App\Events\Orders\PaymentProcessed;
use App\Exceptions\OrderException;
use App\Models\Customer;
use App\Models\DineTable;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\OrderItemChange;
use App\Orders\Tenders;
use App\Services\Orders\OrderService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Event;
use Tests\Support\SafetyNet;

beforeEach(function () {
    ['user' => $this->user, 'shift' => $this->shift] = SafetyNet::till();
    app(SettingsService::class)->set(SettingKey::DINE_IN_SERVICE_CHARGE->value, '0.1');
    $this->orders = app(OrderService::class);
});

describe('opening an order', function () {
    it('opens a dine-in order, reserves its table and broadcasts OrderCreated', function () {
        Event::fake([OrderCreated::class]);

        $order = SafetyNet::open($this->shift, $this->user, 'dine_in', 'T1');

        expect($order->status)->toBe(OrderStatus::PROCESSING)
            ->and($order->type)->toBe('dine_in')
            ->and($order->dine_table_number)->toBe('T1')
            ->and($order->shift_id)->toBe($this->shift->id)
            ->and($order->payment_status)->toBe(PaymentStatus::PENDING)
            ->and((int) $order->order_number)->toBe(1)
            ->and(DineTable::where('table_number', 'T1')->value('order_id'))->toBe($order->id)
            ->and(KitchenTicket::count())->toBe(0);

        Event::assertDispatchedTimes(OrderCreated::class, 1);
        Event::assertDispatched(OrderCreated::class, fn (OrderCreated $e): bool => $e->order->is($order));
    });

    it('numbers cashier orders per shift in sequence', function () {
        $first = SafetyNet::open($this->shift, $this->user, 'takeaway');
        $second = SafetyNet::open($this->shift, $this->user, 'delivery');
        $third = SafetyNet::open($this->shift, $this->user, 'dine_in', 'T2');

        expect([(int) $first->order_number, (int) $second->order_number, (int) $third->order_number])->toBe([1, 2, 3]);
    });

    it('refuses a dine-in order without a table and creates nothing', function () {
        expect(fn () => SafetyNet::open($this->shift, $this->user, 'dine_in'))
            ->toThrow(OrderException::class);

        expect(Order::count())->toBe(0);
    });

    it('refuses a second order on a reserved table and keeps the first reservation', function () {
        $first = SafetyNet::open($this->shift, $this->user, 'dine_in', 'T3');

        expect(fn () => SafetyNet::open($this->shift, $this->user, 'dine_in', 'T3'))
            ->toThrow(OrderException::class);

        expect(Order::count())->toBe(1)
            ->and(DineTable::where('table_number', 'T3')->value('order_id'))->toBe($first->id);
    });
});

describe('saving items', function () {
    it('prices items on the server, totals with dine-in service and sends one kitchen ticket', function () {
        Event::fake([KitchenTicketUpdated::class]);
        $burger = SafetyNet::product(price: 100, cost: 40);
        $order = SafetyNet::open($this->shift, $this->user, 'dine_in', 'T1');

        $saved = SafetyNet::save($order, [[$burger, 2]]);

        // sub_total 200, service 10% = 20, tax 0 -> total 220. profit = 220 - 2 * 40.
        expect((float) $saved->sub_total)->toBe(200.0)
            ->and((float) $saved->service)->toBe(20.0)
            ->and((float) $saved->tax)->toBe(0.0)
            ->and((float) $saved->total)->toBe(220.0)
            ->and((float) $saved->profit)->toBe(140.0)
            ->and((float) $saved->items->first()->price)->toBe(100.0);

        $ticket = $saved->kitchenTicket()->with('items')->firstOrFail();
        expect($ticket->status)->toBe(KitchenTicketStatus::ACTIVE)
            ->and($ticket->items)->toHaveCount(1)
            ->and((float) $ticket->items->first()->quantity)->toBe(2.0);

        Event::assertDispatchedTimes(KitchenTicketUpdated::class, 1);
        Event::assertDispatched(KitchenTicketUpdated::class, fn ($e): bool => $e->reason === KitchenTicketUpdated::REASON_NEW);
    });

    it('takeaway charges no service', function () {
        $order = SafetyNet::open($this->shift, $this->user, 'takeaway');

        $saved = SafetyNet::save($order, [[SafetyNet::product(price: 100), 1]]);

        expect((float) $saved->service)->toBe(0.0)
            ->and((float) $saved->total)->toBe(100.0);
    });

    it('adds a kitchen line for an added quantity and records the change once', function () {
        $burger = SafetyNet::product();
        $order = SafetyNet::open($this->shift, $this->user);

        SafetyNet::save($order, [[$burger, 1]]);
        SafetyNet::save($order, [[$burger, 3]]);

        $ticket = $order->kitchenTicket()->with('items')->firstOrFail();
        expect(KitchenTicket::count())->toBe(1)
            ->and((float) $ticket->items->sum('quantity'))->toBe(3.0)
            ->and(OrderItemChange::where('order_id', $order->id)->count())->toBe(2);
    });

    it('does not touch stock or payments while the order is open', function () {
        $burger = SafetyNet::product(stock: 10);
        $order = SafetyNet::open($this->shift, $this->user);

        SafetyNet::save($order, [[$burger, 4]]);

        expect(SafetyNet::stock($burger))->toBe(10.0)
            ->and($order->payments()->count())->toBe(0);
    });
});

describe('completing an order', function () {
    it('records payments, removes stock, frees the table, finishes the kitchen ticket and broadcasts', function () {
        Event::fake([OrderCompleted::class, PaymentProcessed::class, KitchenTicketUpdated::class]);
        $burger = SafetyNet::product(price: 100, cost: 40, stock: 10);
        $order = SafetyNet::open($this->shift, $this->user, 'dine_in', 'T1');
        SafetyNet::save($order, [[$burger, 2]]);

        $completed = $this->orders->completeOrder($order->id, ['cash' => 100, 'card' => 120]);

        expect($completed->status)->toBe(OrderStatus::COMPLETED)
            ->and($completed->payment_status)->toBe(PaymentStatus::FULL_PAID)
            ->and((float) $completed->payments()->sum('amount'))->toBe(220.0)
            ->and($completed->payments()->pluck('method')->map->value->sort()->values()->all())->toBe(['card', 'cash'])
            ->and($completed->payments()->pluck('shift_id')->unique()->all())->toBe([$this->shift->id])
            ->and(SafetyNet::stock($burger))->toBe(8.0)
            ->and((float) $completed->fresh()->profit)->toBe(140.0)
            ->and(DineTable::where('table_number', 'T1')->value('order_id'))->toBeNull();

        $ticket = $completed->kitchenTicket()->with('items')->firstOrFail();
        expect($ticket->status)->toBe(KitchenTicketStatus::DONE)
            ->and($ticket->auto_finished)->toBeTrue()
            ->and($ticket->items->every(fn ($line) => $line->status === KitchenItemStatus::DONE))->toBeTrue();

        Event::assertDispatchedTimes(OrderCompleted::class, 1);
        Event::assertDispatchedTimes(PaymentProcessed::class, 2);
    });

    it('caps a single overpaying tender at the order total', function () {
        $order = SafetyNet::open($this->shift, $this->user);
        SafetyNet::save($order, [[SafetyNet::product(price: 75), 1]]);

        $completed = $this->orders->completeOrder($order->id, ['cash' => 200]);

        expect((float) $completed->payments()->sum('amount'))->toBe(75.0)
            ->and($completed->payment_status)->toBe(PaymentStatus::FULL_PAID);
    });

    it('rejects split tenders that overpay and changes nothing', function () {
        $burger = SafetyNet::product(price: 100, stock: 10);
        $order = SafetyNet::open($this->shift, $this->user, 'dine_in', 'T1');
        SafetyNet::save($order, [[$burger, 1]]);

        expect(fn () => $this->orders->completeOrder($order->id, ['cash' => 100, 'card' => 100]))
            ->toThrow(OrderException::class);

        $order->refresh();
        expect($order->status)->toBe(OrderStatus::PROCESSING)
            ->and($order->payments()->count())->toBe(0)
            ->and(SafetyNet::stock($burger))->toBe(10.0)
            ->and(DineTable::where('table_number', 'T1')->value('order_id'))->toBe($order->id)
            ->and($order->kitchenTicket->status)->toBe(KitchenTicketStatus::ACTIVE);
    });

    it('rejects an underpaid takeaway and rolls the whole completion back', function () {
        $burger = SafetyNet::product(price: 100, stock: 10);
        $order = SafetyNet::open($this->shift, $this->user);
        SafetyNet::save($order, [[$burger, 1]]);

        expect(fn () => $this->orders->completeOrder($order->id, ['cash' => 40]))
            ->toThrow(OrderException::class);

        $order->refresh();
        expect($order->status)->toBe(OrderStatus::PROCESSING)
            ->and($order->payments()->count())->toBe(0)
            ->and(SafetyNet::stock($burger))->toBe(10.0)
            ->and($order->kitchenTicket->status)->toBe(KitchenTicketStatus::ACTIVE);
    });

    it('refuses to complete an order twice', function () {
        $order = SafetyNet::open($this->shift, $this->user);
        SafetyNet::save($order, [[SafetyNet::product(price: 10), 1]]);
        $this->orders->completeOrder($order->id, ['cash' => 10]);

        expect(fn () => $this->orders->completeOrder($order->id, ['cash' => 10]))
            ->toThrow(OrderException::class);
        expect($order->payments()->count())->toBe(1);
    });

    it('lets a company order close unpaid and collect the rest later', function () {
        Event::fake([PaymentProcessed::class]);
        $order = SafetyNet::open($this->shift, $this->user, 'companies');
        SafetyNet::save($order, [[SafetyNet::product(price: 100), 1]]);

        expect(fn () => $this->orders->completeOrder($order->id, []))
            ->toThrow(OrderException::class, 'يجب ربط الطلب بعميل');

        $this->orders->linkCustomer($order->id, Customer::factory()->create()->id);

        $completed = $this->orders->completeOrder($order->id, []);
        expect($completed->status)->toBe(OrderStatus::COMPLETED)
            ->and($completed->payment_status)->toBe(PaymentStatus::PENDING);

        $collected = $this->orders->collectRemaining($order->id, Tenders::fromArray(['cash' => 100]));
        expect($collected->payment_status)->toBe(PaymentStatus::FULL_PAID)
            ->and((float) $collected->payments()->sum('amount'))->toBe(100.0);
        Event::assertDispatchedTimes(PaymentProcessed::class, 1);
    });

    it('refuses to collect a remaining amount on a takeaway order', function () {
        $order = SafetyNet::open($this->shift, $this->user);
        SafetyNet::save($order, [[SafetyNet::product(price: 10), 1]]);
        $this->orders->completeOrder($order->id, ['cash' => 10]);

        expect(fn () => $this->orders->collectRemaining($order->id, Tenders::fromArray(['cash' => 1])))
            ->toThrow(OrderException::class);
    });
});

describe('cancelling an order', function () {
    it('cancels an open order: frees the table, cancels the ticket, leaves stock and broadcasts', function () {
        Event::fake([OrderCancelled::class, KitchenTicketUpdated::class]);
        $burger = SafetyNet::product(stock: 10);
        $order = SafetyNet::open($this->shift, $this->user, 'dine_in', 'T1');
        SafetyNet::save($order, [[$burger, 2]]);

        $cancelled = $this->orders->cancelOrder($order->id, 'customer left');

        expect($cancelled->status)->toBe(OrderStatus::CANCELLED)
            ->and((float) $cancelled->profit)->toBe(0.0)
            ->and(SafetyNet::stock($burger))->toBe(10.0)
            ->and(DineTable::where('table_number', 'T1')->value('order_id'))->toBeNull()
            ->and($cancelled->kitchenTicket->status)->toBe(KitchenTicketStatus::CANCELLED);

        Event::assertDispatchedTimes(OrderCancelled::class, 1);
        Event::assertDispatched(OrderCancelled::class, fn (OrderCancelled $e): bool => $e->reason === 'customer left');
        Event::assertDispatched(KitchenTicketUpdated::class, fn ($e): bool => $e->reason === KitchenTicketUpdated::REASON_CANCELLED);
    });

    it('cancels a completed order: deletes payments and puts the stock back', function () {
        $burger = SafetyNet::product(price: 100, stock: 10);
        $order = SafetyNet::open($this->shift, $this->user);
        SafetyNet::save($order, [[$burger, 3]]);
        $this->orders->completeOrder($order->id, ['cash' => 300]);
        expect(SafetyNet::stock($burger))->toBe(7.0);

        $cancelled = $this->orders->cancelOrder($order->id);

        expect($cancelled->status)->toBe(OrderStatus::CANCELLED)
            ->and($cancelled->payments()->count())->toBe(0)
            ->and(SafetyNet::stock($burger))->toBe(10.0);
    });

    it('refuses to cancel an order paid in a closed shift', function () {
        $order = SafetyNet::open($this->shift, $this->user);
        SafetyNet::save($order, [[SafetyNet::product(price: 10), 1]]);
        $this->orders->completeOrder($order->id, ['cash' => 10]);
        $this->shift->update(['closed' => true, 'end_at' => now()]);

        expect(fn () => $this->orders->cancelOrder($order->id))->toThrow(OrderException::class);
        expect($order->fresh()->status)->toBe(OrderStatus::COMPLETED)
            ->and($order->payments()->count())->toBe(1);
    });
});

describe('changing the order type', function () {
    it('moves dine-in to takeaway: frees the table, drops service, re-broadcasts the ticket', function () {
        Event::fake([OrderTypeChanged::class, KitchenTicketUpdated::class]);
        $order = SafetyNet::open($this->shift, $this->user, 'dine_in', 'T1');
        SafetyNet::save($order, [[SafetyNet::product(price: 100), 1]]);
        expect((float) $order->fresh()->total)->toBe(110.0);

        $changed = $this->orders->changeOrderType($order->id, 'takeaway');

        expect($changed->type)->toBe('takeaway')
            ->and($changed->dine_table_number)->toBeNull()
            ->and((float) $changed->fresh()->service)->toBe(0.0)
            ->and((float) $changed->fresh()->total)->toBe(100.0)
            ->and(DineTable::where('table_number', 'T1')->value('order_id'))->toBeNull()
            ->and(KitchenTicket::count())->toBe(1);

        Event::assertDispatchedTimes(OrderTypeChanged::class, 1);
        Event::assertDispatched(KitchenTicketUpdated::class, fn ($e): bool => $e->reason === KitchenTicketUpdated::REASON_CHANGED);
    });

    it('moves takeaway to dine-in: needs a table, reserves it and adds service', function () {
        $order = SafetyNet::open($this->shift, $this->user);
        SafetyNet::save($order, [[SafetyNet::product(price: 100), 1]]);

        expect(fn () => $this->orders->changeOrderType($order->id, 'dine_in'))->toThrow(OrderException::class);
        expect($order->fresh()->type)->toBe('takeaway');

        $changed = $this->orders->changeOrderType($order->id, 'dine_in', 'T9');

        expect($changed->dine_table_number)->toBe('T9')
            ->and((float) $changed->fresh()->total)->toBe(110.0)
            ->and(DineTable::where('table_number', 'T9')->value('order_id'))->toBe($order->id);
    });

    it('refuses to change the type of a completed order', function () {
        $order = SafetyNet::open($this->shift, $this->user);
        SafetyNet::save($order, [[SafetyNet::product(price: 10), 1]]);
        $this->orders->completeOrder($order->id, ['cash' => 10]);

        expect(fn () => $this->orders->changeOrderType($order->id, 'delivery'))->toThrow(OrderException::class);
        expect($order->fresh()->type)->toBe('takeaway');
    });
});
