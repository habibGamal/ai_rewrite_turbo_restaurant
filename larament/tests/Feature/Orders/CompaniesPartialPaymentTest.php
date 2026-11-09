<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\OrderException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Orders\Tenders;
use App\Repositories\OrderRepository;
use App\Services\Orders\OrderService;
use App\Services\ShiftsReportService;

use function Pest\Laravel\actingAs;

function companiesOrder(Shift $shift, User $user, ?Customer $customer, string $type = 'companies', float $total = 100): Order
{
    $product = Product::factory()->create(['price' => $total, 'cost' => 40, 'type' => 'manufactured']);

    $order = Order::factory()->create([
        'type' => $type,
        'status' => OrderStatus::PROCESSING,
        'payment_status' => PaymentStatus::PENDING,
        'shift_id' => $shift->id,
        'user_id' => $user->id,
        'customer_id' => $customer?->id,
        'sub_total' => $total,
        'service' => 0,
        'tax' => 0,
        'discount' => 0,
        'temp_discount_percent' => 0,
        'total' => $total,
        'profit' => 60,
    ]);

    $order->items()->create([
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => $total,
        'cost' => 40,
        'total' => $total,
    ]);

    return $order;
}

describe('companies partial payment', function () {
    beforeEach(function () {
        $this->user = User::factory()->admin()->create();
        $this->shift = Shift::factory()->active()->create(['user_id' => $this->user->id]);
        $this->customer = Customer::factory()->create([
            'name' => 'شركة النيل',
            'phone' => '01000000000',
        ]);
        $this->orders = app(OrderService::class);
        actingAs($this->user);
    });

    it('refuses to complete a takeaway order paid 60 of 100', function () {
        $order = companiesOrder($this->shift, $this->user, $this->customer, 'takeaway');

        expect(fn () => $this->orders->completeOrder($order->id, ['cash' => 60]))
            ->toThrow(OrderException::class, 'المبلغ المدفوع أقل من إجمالي الطلب');

        expect($order->fresh()->status)->toBe(OrderStatus::PROCESSING)
            ->and(Payment::query()->where('order_id', $order->id)->exists())->toBeFalse();
    });

    it('completes a companies order paid 60 of 100 as partial and counts the sale in that shift', function () {
        $order = companiesOrder($this->shift, $this->user, $this->customer);

        $completed = $this->orders->completeOrder($order->id, ['cash' => 60]);

        expect($completed->status)->toBe(OrderStatus::COMPLETED)
            ->and($completed->payment_status)->toBe(PaymentStatus::PARTIAL_PAID)
            ->and((float) $completed->total)->toBe(100.0);

        $stats = app(ShiftsReportService::class)->calculateShiftStats($this->shift->fresh());

        expect((float) $stats['sales'])->toBe(100.0)
            ->and((float) $stats['cashPayments'])->toBe(60.0);

        $html = view('print.receipt-template', ['order' => $completed->fresh(['payments', 'items', 'customer'])])->render();

        expect($html)->toContain('المدفوع')
            ->and($html)->toContain('المتبقي')
            ->and($html)->toContain('60.00')
            ->and($html)->toContain('40.00')
            ->and($html)->toContain('شركة النيل');
    });

    it('collects the rest in a later shift and counts that cash only there', function () {
        $order = companiesOrder($this->shift, $this->user, $this->customer);
        $this->orders->completeOrder($order->id, ['cash' => 60]);

        $this->shift->update(['closed' => true, 'end_at' => now()]);
        $shift2 = Shift::factory()->active()->create(['user_id' => $this->user->id]);

        $this->post(route('orders.collectRemaining', $order), [
            'cash' => 40,
            'card' => 0,
            'talabat_card' => 0,
        ])->assertRedirect()
            ->assertSessionHas('success', 'تم تحصيل المتبقي بنجاح');

        $order->refresh();

        expect($order->payment_status)->toBe(PaymentStatus::FULL_PAID)
            ->and(app(OrderRepository::class)->partialPaidForTypes(['companies']))->toHaveCount(0);

        $shift1Stats = app(ShiftsReportService::class)->calculateShiftStats($this->shift->fresh());
        $shift2Stats = app(ShiftsReportService::class)->calculateShiftStats($shift2);

        expect((float) $shift2Stats['cashPayments'])->toBe(40.0)
            ->and((float) $shift1Stats['cashPayments'])->toBe(60.0);

        $collection = Payment::query()
            ->where('order_id', $order->id)
            ->where('amount', 40)
            ->first();

        expect($collection)->not->toBeNull()
            ->and($collection->shift_id)->toBe($shift2->id)
            ->and($collection->method)->toBe(PaymentMethod::CASH);
    });

    it('caps one cash tender over the remaining amount and rejects several tenders over it', function () {
        $over = companiesOrder($this->shift, $this->user, $this->customer);
        $this->orders->completeOrder($over->id, ['cash' => 60]);

        expect(fn () => $this->orders->collectRemaining($over->id, Tenders::fromArray([
            'cash' => 30,
            'card' => 20,
        ])))->toThrow(OrderException::class);

        expect(Payment::query()->where('order_id', $over->id)->count())->toBe(1)
            ->and($over->fresh()->payment_status)->toBe(PaymentStatus::PARTIAL_PAID);

        $capped = companiesOrder($this->shift, $this->user, $this->customer);
        $this->orders->completeOrder($capped->id, ['cash' => 60]);

        $collected = $this->orders->collectRemaining($capped->id, Tenders::fromArray(['cash' => 50]));

        expect($collected->payment_status)->toBe(PaymentStatus::FULL_PAID)
            ->and((float) Payment::query()->where('order_id', $capped->id)->sum('amount'))->toBe(100.0)
            ->and((float) Payment::query()->where('order_id', $capped->id)->where('amount', 40)->value('amount'))->toBe(40.0);
    });

    it('refuses a partly paid companies order that has no customer', function () {
        $order = companiesOrder($this->shift, $this->user, null);

        expect(fn () => $this->orders->completeOrder($order->id, ['cash' => 60]))
            ->toThrow(OrderException::class, 'يجب ربط الطلب بعميل قبل الدفع الجزئي');

        expect($order->fresh()->status)->toBe(OrderStatus::PROCESSING)
            ->and(Payment::query()->where('order_id', $order->id)->exists())->toBeFalse();
    });

    it('refuses collection on an order that is not partial or not a companies behavior', function () {
        $full = companiesOrder($this->shift, $this->user, $this->customer);
        $this->orders->completeOrder($full->id, ['cash' => 100]);

        expect(fn () => $this->orders->collectRemaining($full->id, Tenders::fromArray(['cash' => 10])))
            ->toThrow(OrderException::class, 'لا يمكن تحصيل متبقي إلا لطلب مكتمل ومدفوع جزئياً');

        $takeaway = Order::factory()->create([
            'type' => 'takeaway',
            'status' => OrderStatus::COMPLETED,
            'payment_status' => PaymentStatus::PARTIAL_PAID,
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'total' => 100,
        ]);

        expect(fn () => $this->orders->collectRemaining($takeaway->id, Tenders::fromArray(['cash' => 10])))
            ->toThrow(OrderException::class, 'هذا النوع من الطلبات لا يسمح بتحصيل المتبقي');
    });

    it('refuses to cancel a partly paid order whose collection is in a closed shift', function () {
        $order = companiesOrder($this->shift, $this->user, $this->customer);
        $this->orders->completeOrder($order->id, ['cash' => 60]);

        $this->shift->update(['closed' => true, 'end_at' => now()]);
        $shift2 = Shift::factory()->active()->create(['user_id' => $this->user->id]);
        $this->orders->collectRemaining($order->id, Tenders::fromArray(['cash' => 20]));
        $shift2->update(['closed' => true, 'end_at' => now()]);

        expect(fn () => $this->orders->cancelOrder($order->id))
            ->toThrow(OrderException::class, 'لا يمكن إلغاء الطلب لأن إحدى دفعاته تخص شيفتًا مغلقًا');

        expect($order->fresh()->status)->toBe(OrderStatus::COMPLETED)
            ->and(Payment::query()->where('order_id', $order->id)->count())->toBe(2);
    });

    it('still cancels a fully paid takeaway order from a closed shift', function () {
        openInventoryDay();
        $order = companiesOrder($this->shift, $this->user, null, 'takeaway');
        $this->orders->completeOrder($order->id, ['cash' => 100]);
        $this->shift->update(['closed' => true, 'end_at' => now()]);

        $this->orders->cancelOrder($order->id);

        expect($order->fresh()->status)->toBe(OrderStatus::CANCELLED);
    });

    it('sends receivables for partly paid companies orders and omits the old prop', function () {
        $order = companiesOrder($this->shift, $this->user, $this->customer);
        $completed = $this->orders->completeOrder($order->id, ['cash' => 60]);

        $this->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(function ($page) use ($completed) {
                $page->has('receivables', 1)
                    ->where('receivables.0.id', $completed->id)
                    ->where('receivables.0.display.label', 'شركات')
                    ->where('receivables.0.display.showCustomer', true)
                    ->where('receivables.0.total', 100)
                    ->where('receivables.0.paid', 60)
                    ->where('receivables.0.remaining', 40)
                    ->missing('previousPartialPaidOrders');
            });
    });

    it('still completes a fully paid web order', function () {
        $order = companiesOrder($this->shift, $this->user, $this->customer, 'web_takeaway', 80);
        $order->update([
            'sub_total' => 80,
            'service' => 5,
            'total' => 80,
        ]);

        $completed = $this->orders->completeOrder($order->id, ['cash' => 80]);

        expect($completed->status)->toBe(OrderStatus::COMPLETED)
            ->and($completed->payment_status)->toBe(PaymentStatus::FULL_PAID)
            ->and($completed->total)->toBe('80.00')
            ->and($completed->service)->toBe('5.00');
    });
});
