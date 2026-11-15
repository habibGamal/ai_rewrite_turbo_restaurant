<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Events\Orders\PaymentProcessedBroadcast;
use App\Exceptions\OrderException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shift;
use App\Orders\Tenders;
use App\Services\Orders\OrderPaymentService;
use Illuminate\Support\Facades\Event;
use Tests\Unit\TestCase;

uses(TestCase::class);

describe('OrderPaymentService', function () {
    beforeEach(function () {
        Event::fake([PaymentProcessedBroadcast::class]);

        $this->service = app(OrderPaymentService::class);
        $this->order = Order::factory()->create([
            'total' => 100.00,
            'payment_status' => PaymentStatus::PENDING,
        ]);
    });

    it('creates payment and updates order status for full payment', function () {
        $result = $this->service->pay($this->order, Tenders::fromArray([
            'cash' => 100.00,
        ]), $this->order->shift_id);

        expect($result->payment_status)->toBe(PaymentStatus::FULL_PAID)
            ->and(Payment::where('order_id', $this->order->id)->count())->toBe(1);

        Event::assertDispatched(PaymentProcessedBroadcast::class);
    });

    it('sets partial paid status when payment is less than total', function () {
        $result = $this->service->pay($this->order, Tenders::fromArray([
            'cash' => 50.00,
        ]), $this->order->shift_id);

        expect($result->payment_status)->toBe(PaymentStatus::PARTIAL_PAID)
            ->and(Payment::where('order_id', $this->order->id)->first()->amount)->toBe('50.00');
    });

    it('caps a single cash tender over the remaining amount and marks the order full paid', function () {
        $result = $this->service->pay($this->order, Tenders::fromArray([
            'cash' => 150.00,
        ]), $this->order->shift_id);

        expect($result->payment_status)->toBe(PaymentStatus::FULL_PAID)
            ->and(Payment::where('order_id', $this->order->id)->count())->toBe(1)
            ->and(Payment::where('order_id', $this->order->id)->first()->amount)->toBe('100.00')
            ->and(Payment::where('order_id', $this->order->id)->first()->method)->toBe(PaymentMethod::CASH);

        Event::assertDispatched(PaymentProcessedBroadcast::class);
    });

    it('caps a single tender at the unpaid balance when the order is already partly paid', function () {
        Payment::factory()->create([
            'order_id' => $this->order->id,
            'shift_id' => $this->order->shift_id,
            'amount' => 40,
            'method' => PaymentMethod::CARD,
        ]);

        $result = $this->service->pay($this->order, Tenders::fromArray([
            'cash' => 80,
        ]), $this->order->shift_id);

        $cash = Payment::where('order_id', $this->order->id)->where('method', PaymentMethod::CASH)->first();

        expect($result->payment_status)->toBe(PaymentStatus::FULL_PAID)
            ->and($cash->amount)->toBe('60.00');
    });

    it('processes multiple payment methods', function () {
        $this->service->pay($this->order, Tenders::fromArray([
            'cash' => 60.00,
            'card' => 40.00,
        ]), $this->order->shift_id);

        expect(Payment::where('order_id', $this->order->id)->count())->toBe(2)
            ->and($this->order->fresh()->payment_status)->toBe(PaymentStatus::FULL_PAID)
            ->and(Payment::where('order_id', $this->order->id)->where('method', PaymentMethod::CASH)->first()->amount)->toBe('60.00')
            ->and(Payment::where('order_id', $this->order->id)->where('method', PaymentMethod::CARD)->first()->amount)->toBe('40.00');

        $totalPaid = Payment::where('order_id', $this->order->id)->sum('amount');
        expect($totalPaid)->toBe(100);
    });

    it('throws when several tenders exceed the remaining amount and records nothing', function () {
        $tenders = Tenders::fromArray([
            'cash' => 60.00,
            'card' => 50.00,
        ]);

        $totalPaid = 0;
        $totalNewPayments = 110.0;
        $message = "إجمالي المدفوعات ({$totalPaid} + {$totalNewPayments} = ".($totalPaid + $totalNewPayments).') يتجاوز إجمالي الطلب ('.$this->order->total.')';

        expect(fn () => $this->service->pay($this->order, $tenders, $this->order->shift_id))
            ->toThrow(OrderException::class, $message);

        expect(Payment::where('order_id', $this->order->id)->count())->toBe(0)
            ->and($this->order->fresh()->payment_status)->toBe(PaymentStatus::PENDING);
    });

    it('skips zero amount payments', function () {
        $this->service->pay($this->order, Tenders::fromArray([
            'cash' => 100.00,
            'card' => 0.00,
        ]), $this->order->shift_id);

        expect(Payment::where('order_id', $this->order->id)->count())->toBe(1);
    });

    it('records nothing for empty tenders and refreshes the payment status', function () {
        $this->order->update(['payment_status' => PaymentStatus::PARTIAL_PAID]);

        $result = $this->service->pay($this->order, Tenders::fromArray([
            'cash' => 0,
            'card' => 0,
            'talabat_card' => 0,
        ]), $this->order->shift_id);

        expect($result->payment_status)->toBe(PaymentStatus::PENDING)
            ->and(Payment::where('order_id', $this->order->id)->count())->toBe(0);

        Event::assertNotDispatched(PaymentProcessedBroadcast::class);
    });

    it('marks a zero-total order full paid when no tender is given', function () {
        $this->order->update(['total' => 0, 'payment_status' => PaymentStatus::PENDING]);

        $result = $this->service->pay($this->order, Tenders::fromArray(['cash' => 0]), $this->order->shift_id);

        expect($result->payment_status)->toBe(PaymentStatus::FULL_PAID)
            ->and(Payment::where('order_id', $this->order->id)->count())->toBe(0);
    });

    it('records the shift id passed to pay even when it differs from the order shift', function () {
        $otherShift = Shift::factory()->create();

        $this->service->pay($this->order, Tenders::fromArray([
            'cash' => 100.00,
        ]), $otherShift->id);

        $payment = Payment::where('order_id', $this->order->id)->first();

        expect($payment->shift_id)->toBe($otherShift->id)
            ->and($payment->shift_id)->not->toBe($this->order->shift_id);
    });

    it('drops zero amounts and rejects an unknown method key', function () {
        $tenders = Tenders::fromArray([
            'cash' => '80',
            'card' => 0,
            'talabat_card' => -5,
        ]);

        expect($tenders->isEmpty())->toBeFalse()
            ->and($tenders->count())->toBe(1)
            ->and($tenders->amounts())->toBe(['cash' => 80.0])
            ->and($tenders->total())->toBe(80.0);

        expect(fn () => Tenders::fromArray(['cash' => 10, 'voucher' => 5]))
            ->toThrow(InvalidArgumentException::class);
    });

    it('caps cash overpayment to the order total when completing through orders.complete', function () {
        $shift = Shift::factory()->active()->create();

        $order = Order::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => auth()->id(),
            'status' => OrderStatus::PROCESSING,
            'type' => 'takeaway',
            'sub_total' => 100,
            'tax' => 0,
            'service' => 0,
            'discount' => 0,
            'temp_discount_percent' => 0,
            'total' => 100,
            'payment_status' => PaymentStatus::PENDING,
            'dine_table_number' => null,
        ]);

        $product = Product::factory()->create([
            'price' => 100,
            'cost' => 40,
            'type' => 'manufactured',
            'product_ref' => 'P000100',
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 100,
            'cost' => 40,
            'total' => 100,
        ]);

        $response = $this->post(route('orders.complete', $order), [
            'cash' => 150,
            'card' => 0,
            'talabat_card' => 0,
            'print' => false,
        ]);

        $response->assertRedirect(route('orders.index').'#takeaway')
            ->assertSessionHas('success', 'تم إنهاء الطلب بنجاح');

        $order->refresh();
        $payment = Payment::where('order_id', $order->id)->first();

        expect($order->status)->toBe(OrderStatus::COMPLETED)
            ->and($order->payment_status)->toBe(PaymentStatus::FULL_PAID)
            ->and(Payment::where('order_id', $order->id)->count())->toBe(1)
            ->and($payment->amount)->toBe('100.00')
            ->and($payment->method)->toBe(PaymentMethod::CASH);
    });
});
