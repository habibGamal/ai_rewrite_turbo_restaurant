<?php

/*
 * Phase 0 safety net: external seller splits and order returns.
 *
 * The seller reversal on cancel is planned to move into an in-transaction
 * listener, and returns will gain an OrderReturned event. These tests pin the
 * money, stock and rollback behaviour those moves must keep.
 */

use App\Enums\KitchenTicketStatus;
use App\Enums\OptionalFeature;
use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Enums\SettingKey;
use App\Exceptions\OrderException;
use App\Models\DineTable;
use App\Models\ExternalSeller;
use App\Models\OrderReturn;
use App\Models\Refund;
use App\Services\FeatureService;
use App\Services\Orders\ExternalOrderService;
use App\Services\Orders\ExternalSellerSettlementService;
use App\Services\Orders\OrderReturnService;
use App\Services\Orders\OrderService;
use App\Services\SettingsService;
use Tests\Support\SafetyNet;

beforeEach(function () {
    ['user' => $this->user, 'shift' => $this->shift] = SafetyNet::till();
    $this->orders = app(OrderService::class);
    app(SettingsService::class)->set(SettingKey::DINE_IN_SERVICE_CHARGE->value, '0');
});

describe('external sellers', function () {
    beforeEach(function () {
        app(FeatureService::class)->enable(OptionalFeature::OUTSOURCED_ORDERS);
        $this->seller = ExternalSeller::create(['name' => 'عربة الحلو', 'is_active' => true]);
        $this->dessert = $this->seller->products()->create(['name' => 'كنافة', 'price' => 30]);
    });

    it('keeps seller money out of the restaurant order through complete and cancel', function () {
        $burger = SafetyNet::product(price: 100, cost: 40, stock: 10);
        $order = SafetyNet::open($this->shift, $this->user, 'dine_in', 'T1');
        SafetyNet::save($order, [[$burger, 1]]);
        $external = app(ExternalOrderService::class)->save($order->id, $this->seller->id, [
            ['product_id' => $this->dessert->id, 'quantity' => 2],
        ]);
        $settlements = app(ExternalSellerSettlementService::class);

        // Guest pays 100 card + 60 cash. Cash goes to the seller first.
        $completed = $this->orders->completeOrder($order->id, ['card' => 100, 'cash' => 60]);

        expect((float) $external->fresh()->total)->toBe(60.0)
            ->and((float) $completed->total)->toBe(100.0)
            ->and((float) $completed->payments()->sum('amount'))->toBe(100.0)
            ->and($completed->payments()->pluck('method')->map->value->all())->toBe(['card'])
            ->and((float) $external->collections()->sum('amount'))->toBe(60.0)
            ->and($external->collections()->pluck('method')->all())->toBe(['cash'])
            ->and($settlements->payable($this->seller))->toBe(60.0)
            ->and(SafetyNet::stock($burger))->toBe(9.0);

        $settlements->pay($this->seller->id, 60, null);
        expect($settlements->payable($this->seller))->toBe(0.0);

        $this->orders->cancelOrder($order->id);

        expect($external->fresh()->status)->toBe('cancelled')
            ->and($external->collections()->count())->toBe(0)
            ->and((float) $this->seller->settlements()->sum('amount'))->toBe(0.0)
            ->and((float) $this->seller->settlements()->min('amount'))->toBe(-60.0)
            ->and($settlements->payable($this->seller))->toBe(0.0)
            ->and(SafetyNet::stock($burger))->toBe(10.0);
    });

    it('rolls the whole cancel back when a settled seller cannot be reversed without an open shift', function () {
        $order = SafetyNet::open($this->shift, $this->user, 'dine_in', 'T1');
        SafetyNet::save($order, [[SafetyNet::product(price: 100), 1]]);
        $external = app(ExternalOrderService::class)->save($order->id, $this->seller->id, [
            ['product_id' => $this->dessert->id, 'quantity' => 1],
        ]);
        $external->collections()->create(['shift_id' => $this->shift->id, 'user_id' => $this->user->id, 'method' => 'cash', 'amount' => 30]);
        app(ExternalSellerSettlementService::class)->pay($this->seller->id, 30, null);
        // Close the shift the order lives in; the reversal needs an open one.
        $this->shift->update(['closed' => true, 'end_at' => now()]);

        expect(fn () => $this->orders->cancelOrder($order->id))
            ->toThrow(OrderException::class, 'يجب فتح شيفت');

        $order->refresh();
        expect($order->status)->toBe(OrderStatus::PROCESSING)
            ->and($external->fresh()->status)->not->toBe('cancelled')
            ->and($external->collections()->count())->toBe(1)
            ->and($this->seller->settlements()->count())->toBe(1)
            ->and(DineTable::where('table_number', 'T1')->value('order_id'))->toBe($order->id)
            ->and($order->kitchenTicket->status)->toBe(KitchenTicketStatus::ACTIVE);
    });

    it('cancels an unsettled seller order without creating a settlement', function () {
        $order = SafetyNet::open($this->shift, $this->user);
        $external = app(ExternalOrderService::class)->save($order->id, $this->seller->id, [
            ['product_id' => $this->dessert->id, 'quantity' => 1],
        ]);

        $this->orders->cancelOrder($order->id);

        expect($external->fresh()->status)->toBe('cancelled')
            ->and($this->seller->settlements()->count())->toBe(0);
    });

    it('refuses to change to a credit order type while a seller order is open', function () {
        $order = SafetyNet::open($this->shift, $this->user);
        app(ExternalOrderService::class)->save($order->id, $this->seller->id, [
            ['product_id' => $this->dessert->id, 'quantity' => 1],
        ]);

        expect(fn () => $this->orders->changeOrderType($order->id, 'companies'))
            ->toThrow(OrderException::class);
        expect($order->fresh()->type)->toBe('takeaway');
    });
});

describe('returns', function () {
    beforeEach(function () {
        $this->burger = SafetyNet::product(price: 50, cost: 20, stock: 10);
        $this->fries = SafetyNet::product(price: 10, cost: 2, stock: 10);
        $order = SafetyNet::open($this->shift, $this->user);
        SafetyNet::save($order, [[$this->burger, 2], [$this->fries, 3]]);
        $this->order = $this->orders->completeOrder($order->id, ['cash' => 130]);
        $this->burgerLine = $this->order->items()->where('product_id', $this->burger->id)->sole();
        $this->friesLine = $this->order->items()->where('product_id', $this->fries->id)->sole();
        $this->returns = app(OrderReturnService::class);
    });

    it('returns part of an order, refunds it and puts the stock back', function () {
        $return = $this->returns->processReturn(
            $this->order,
            [['order_item_id' => $this->burgerLine->id, 'quantity' => 1, 'refund_amount' => 50]],
            'cold',
            [['method' => 'cash', 'amount' => 30], ['method' => 'card', 'amount' => 20]],
            $this->shift->id,
        );

        expect((float) $return->total_refund)->toBe(50.0)
            ->and($return->shift_id)->toBe($this->shift->id)
            ->and($return->user_id)->toBe($this->user->id)
            ->and(Refund::where('order_return_id', $return->id)->sum('amount'))->toEqual(50)
            ->and($this->order->fresh()->return_status)->toBe(ReturnStatus::PARTIAL_RETURN)
            ->and(SafetyNet::stock($this->burger))->toBe(9.0)
            // A return does not touch the order itself: status, payments and total stay.
            ->and($this->order->fresh()->status)->toBe(OrderStatus::COMPLETED)
            ->and((float) $this->order->fresh()->total)->toBe(130.0)
            ->and((float) $this->order->payments()->sum('amount'))->toBe(130.0);
    });

    it('marks a full return once every unit is back, over several returns', function () {
        $this->returns->processReturn($this->order, [
            ['order_item_id' => $this->burgerLine->id, 'quantity' => 2, 'refund_amount' => 100],
        ], 'r1', [['method' => 'cash', 'amount' => 100]], $this->shift->id);
        $this->returns->processReturn($this->order->fresh(), [
            ['order_item_id' => $this->friesLine->id, 'quantity' => 3, 'refund_amount' => 30],
        ], 'r2', [['method' => 'cash', 'amount' => 30]], $this->shift->id);

        expect($this->order->fresh()->return_status)->toBe(ReturnStatus::FULL_RETURN)
            ->and(OrderReturn::count())->toBe(2)
            ->and(SafetyNet::stock($this->burger))->toBe(10.0)
            ->and(SafetyNet::stock($this->fries))->toBe(10.0);
    });

    it('can refund without putting stock back', function () {
        $this->returns->processReturn($this->order, [
            ['order_item_id' => $this->burgerLine->id, 'quantity' => 1, 'refund_amount' => 50],
        ], 'spilled', [['method' => 'cash', 'amount' => 50]], $this->shift->id, reverseStock: false);

        expect(SafetyNet::stock($this->burger))->toBe(8.0)
            ->and($this->order->fresh()->return_status)->toBe(ReturnStatus::PARTIAL_RETURN);
    });

    it('refuses to return more than was sold, counting earlier returns', function () {
        $this->returns->processReturn($this->order, [
            ['order_item_id' => $this->burgerLine->id, 'quantity' => 1, 'refund_amount' => 50],
        ], 'r1', [['method' => 'cash', 'amount' => 50]], $this->shift->id);

        expect(fn () => $this->returns->processReturn($this->order->fresh(), [
            ['order_item_id' => $this->burgerLine->id, 'quantity' => 2, 'refund_amount' => 100],
        ], 'r2', [['method' => 'cash', 'amount' => 100]], $this->shift->id))->toThrow(Exception::class);

        expect(OrderReturn::count())->toBe(1)
            ->and(SafetyNet::stock($this->burger))->toBe(9.0);
    });

    it('refuses a refund split that does not add up and saves nothing', function () {
        expect(fn () => $this->returns->processReturn($this->order, [
            ['order_item_id' => $this->burgerLine->id, 'quantity' => 1, 'refund_amount' => 50],
        ], 'bad', [['method' => 'cash', 'amount' => 40]], $this->shift->id))->toThrow(Exception::class);

        expect(OrderReturn::count())->toBe(0)
            ->and(Refund::count())->toBe(0)
            ->and(SafetyNet::stock($this->burger))->toBe(8.0)
            ->and($this->order->fresh()->return_status)->toBe(ReturnStatus::NONE);
    });
});
