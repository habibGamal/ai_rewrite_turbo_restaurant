<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\OrderException;
use App\Models\Customer;
use App\Models\ExternalSeller;
use App\Models\Order;
use App\Models\OrderTypeRow;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Orders\OrderTypeCatalog;
use App\Repositories\OrderRepository;
use App\Services\Orders\ExternalOrderService;
use App\Services\Orders\OrderService;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

function makeOrder(Shift $shift, User $user, ?Customer $customer, string $type = 'takeaway', float $total = 100): Order
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

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->shift = Shift::factory()->active()->create(['user_id' => $this->user->id]);
    $this->customer = Customer::factory()->create([
        'name' => 'عميل تجريبي',
        'phone' => '01122334455',
    ]);
    $this->orders = app(OrderService::class);
    actingAs($this->user);
});

describe('companies seed values', function () {
    it('seeds companies with partial on and pay later on, others default to off', function () {
        $companies = OrderTypeRow::query()->where('code', 'companies')->firstOrFail();
        expect($companies->allow_partial_payment)->toBeTrue()
            ->and($companies->allow_pay_later)->toBeTrue()
            ->and($companies->require_customer_for_credit)->toBeTrue()
            ->and($companies->allowsCredit())->toBeTrue()
            ->and($companies->allowsPartialPayment())->toBeTrue()
            ->and($companies->allowsPayLater())->toBeTrue()
            ->and($companies->requiresCustomerForCredit())->toBeTrue();

        $others = OrderTypeRow::query()->where('code', '!=', 'companies')->get();
        foreach ($others as $row) {
            expect($row->allow_partial_payment)->toBeFalse()
                ->and($row->allow_pay_later)->toBeFalse()
                ->and($row->require_customer_for_credit)->toBeTrue()
                ->and($row->allowsCredit())->toBeFalse();
        }
    });
});

describe('order completion credit rules (item 3)', function () {
    it('completes a takeaway order with partial on, paid 60 of 100 as partial_paid with 40 in receivables', function () {
        $row = OrderTypeRow::query()->where('code', 'takeaway')->firstOrFail();
        $row->update([
            'allow_partial_payment' => true,
            'allow_pay_later' => false,
            'require_customer_for_credit' => true,
        ]);
        app(OrderTypeCatalog::class)->flush();

        $order = makeOrder($this->shift, $this->user, $this->customer, 'takeaway', 100);

        $completed = $this->orders->completeOrder($order->id, ['cash' => 60]);

        expect($completed->status)->toBe(OrderStatus::COMPLETED)
            ->and($completed->payment_status)->toBe(PaymentStatus::PARTIAL_PAID);

        $receivables = app(OrderRepository::class)->receivablesForTypes(['takeaway']);
        expect($receivables)->toHaveCount(1)
            ->and($receivables->first()->id)->toBe($order->id);
    });

    it('completes a takeaway order with pay later on, paid 0 as pending with 100 in receivables', function () {
        $row = OrderTypeRow::query()->where('code', 'takeaway')->firstOrFail();
        $row->update([
            'allow_partial_payment' => false,
            'allow_pay_later' => true,
            'require_customer_for_credit' => true,
        ]);
        app(OrderTypeCatalog::class)->flush();

        $order = makeOrder($this->shift, $this->user, $this->customer, 'takeaway', 100);

        $completed = $this->orders->completeOrder($order->id, []);

        expect($completed->status)->toBe(OrderStatus::COMPLETED)
            ->and($completed->payment_status)->toBe(PaymentStatus::PENDING);

        $receivables = app(OrderRepository::class)->receivablesForTypes(['takeaway']);
        expect($receivables)->toHaveCount(1)
            ->and($receivables->first()->id)->toBe($order->id);
    });

    it('refuses completion on a takeaway order when both credit flags are off and paid 60 of 100', function () {
        $row = OrderTypeRow::query()->where('code', 'takeaway')->firstOrFail();
        $row->update([
            'allow_partial_payment' => false,
            'allow_pay_later' => false,
        ]);
        app(OrderTypeCatalog::class)->flush();

        $order = makeOrder($this->shift, $this->user, $this->customer, 'takeaway', 100);

        expect(fn () => $this->orders->completeOrder($order->id, ['cash' => 60]))
            ->toThrow(OrderException::class, 'المبلغ المدفوع أقل من إجمالي الطلب');

        expect($order->fresh()->status)->toBe(OrderStatus::PROCESSING);
    });

    it('refuses completion on a takeaway order when both credit flags are off and paid 0 of 100', function () {
        $row = OrderTypeRow::query()->where('code', 'takeaway')->firstOrFail();
        $row->update([
            'allow_partial_payment' => false,
            'allow_pay_later' => false,
        ]);
        app(OrderTypeCatalog::class)->flush();

        $order = makeOrder($this->shift, $this->user, $this->customer, 'takeaway', 100);

        expect(fn () => $this->orders->completeOrder($order->id, []))
            ->toThrow(OrderException::class, 'هذا النوع لا يسمح بالإغلاق والدفع لاحقاً');

        expect($order->fresh()->status)->toBe(OrderStatus::PROCESSING);
    });
});

describe('customer requirement on and off', function () {
    it('refuses partial payment when require_customer_for_credit is on and no customer is linked', function () {
        $row = OrderTypeRow::query()->where('code', 'takeaway')->firstOrFail();
        $row->update([
            'allow_partial_payment' => true,
            'allow_pay_later' => false,
            'require_customer_for_credit' => true,
        ]);
        app(OrderTypeCatalog::class)->flush();

        $order = makeOrder($this->shift, $this->user, null, 'takeaway', 100);

        expect(fn () => $this->orders->completeOrder($order->id, ['cash' => 60]))
            ->toThrow(OrderException::class, 'يجب ربط الطلب بعميل قبل الدفع الآجل');
    });

    it('refuses pay later when require_customer_for_credit is on and no customer is linked', function () {
        $row = OrderTypeRow::query()->where('code', 'takeaway')->firstOrFail();
        $row->update([
            'allow_partial_payment' => false,
            'allow_pay_later' => true,
            'require_customer_for_credit' => true,
        ]);
        app(OrderTypeCatalog::class)->flush();

        $order = makeOrder($this->shift, $this->user, null, 'takeaway', 100);

        expect(fn () => $this->orders->completeOrder($order->id, []))
            ->toThrow(OrderException::class, 'يجب ربط الطلب بعميل قبل الدفع الآجل');
    });

    it('allows partial payment when require_customer_for_credit is off and no customer is linked', function () {
        $row = OrderTypeRow::query()->where('code', 'takeaway')->firstOrFail();
        $row->update([
            'allow_partial_payment' => true,
            'allow_pay_later' => false,
            'require_customer_for_credit' => false,
        ]);
        app(OrderTypeCatalog::class)->flush();

        $order = makeOrder($this->shift, $this->user, null, 'takeaway', 100);

        $completed = $this->orders->completeOrder($order->id, ['cash' => 60]);

        expect($completed->status)->toBe(OrderStatus::COMPLETED)
            ->and($completed->payment_status)->toBe(PaymentStatus::PARTIAL_PAID);
    });

    it('allows pay later when require_customer_for_credit is off and no customer is linked', function () {
        $row = OrderTypeRow::query()->where('code', 'takeaway')->firstOrFail();
        $row->update([
            'allow_partial_payment' => false,
            'allow_pay_later' => true,
            'require_customer_for_credit' => false,
        ]);
        app(OrderTypeCatalog::class)->flush();

        $order = makeOrder($this->shift, $this->user, null, 'takeaway', 100);

        $completed = $this->orders->completeOrder($order->id, []);

        expect($completed->status)->toBe(OrderStatus::COMPLETED)
            ->and($completed->payment_status)->toBe(PaymentStatus::PENDING);
    });
});

describe('pay later receivables lifecycle (item 4)', function () {
    it('appears in receivables when completed with 0 payment, leaves after full collection in new shift', function () {
        $row = OrderTypeRow::query()->where('code', 'companies')->firstOrFail();
        expect($row->allowsPayLater())->toBeTrue();

        $order = makeOrder($this->shift, $this->user, $this->customer, 'companies', 100);

        $completed = $this->orders->completeOrder($order->id, []);

        expect($completed->status)->toBe(OrderStatus::COMPLETED)
            ->and($completed->payment_status)->toBe(PaymentStatus::PENDING);

        // Check receivables in index
        $this->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('receivables', 1)
                ->where('receivables.0.id', $order->id)
                ->where('receivables.0.total', 100)
                ->where('receivables.0.paid', 0)
                ->where('receivables.0.remaining', 100));

        // Close current shift and open a second shift
        $this->shift->update(['closed' => true, 'end_at' => now()]);
        $shift2 = Shift::factory()->active()->create(['user_id' => $this->user->id]);

        // Collect remaining 100 in the new shift
        $this->post(route('orders.collectRemaining', $order), [
            'cash' => 100,
            'card' => 0,
            'talabat_card' => 0,
        ])->assertRedirect()
            ->assertSessionHas('success', 'تم تحصيل المتبقي بنجاح');

        $order->refresh();
        expect($order->payment_status)->toBe(PaymentStatus::FULL_PAID);

        // Verify it leaves receivables
        expect(app(OrderRepository::class)->receivablesForTypes(['companies']))->toHaveCount(0);

        // Verify the payment is recorded in the new shift
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        expect($payment->shift_id)->toBe($shift2->id)
            ->and((float) $payment->amount)->toBe(100.0);
    });
});

describe('external orders refused on credit-allowing types (item 5)', function () {
    it('refuses to add an external order on an order type that allows credit', function () {
        $row = OrderTypeRow::query()->where('code', 'takeaway')->firstOrFail();
        $row->update([
            'allow_partial_payment' => true,
        ]);
        app(OrderTypeCatalog::class)->flush();

        $order = makeOrder($this->shift, $this->user, $this->customer, 'takeaway', 100);
        $seller = ExternalSeller::create(['name' => 'بائع تجريبي', 'is_active' => true]);
        $product = $seller->products()->create(['name' => 'منتج', 'price' => 25, 'is_active' => true]);

        expect(fn () => app(ExternalOrderService::class)->save($order->id, $seller->id, [
            ['product_id' => $product->id, 'quantity' => 1],
        ]))->toThrow(OrderException::class, 'لا يمكن إضافة طلب خارجي لهذا الطلب');
    });

    it('refuses to change an order type with an external order to a credit-allowing type', function () {
        $takeaway = OrderTypeRow::query()->where('code', 'takeaway')->firstOrFail();
        $takeaway->update([
            'allow_partial_payment' => false,
            'allow_pay_later' => false,
        ]);
        $companies = OrderTypeRow::query()->where('code', 'companies')->firstOrFail();
        expect($companies->allowsCredit())->toBeTrue();
        app(OrderTypeCatalog::class)->flush();

        $order = makeOrder($this->shift, $this->user, $this->customer, 'takeaway', 100);
        $seller = ExternalSeller::create(['name' => 'بائع تجريبي', 'is_active' => true]);
        $product = $seller->products()->create(['name' => 'منتج', 'price' => 25, 'is_active' => true]);

        app(ExternalOrderService::class)->save($order->id, $seller->id, [
            ['product_id' => $product->id, 'quantity' => 1],
        ]);

        expect(fn () => $this->orders->changeOrderType($order->id, 'companies'))
            ->toThrow(OrderException::class, 'لا يمكن تغيير النوع مع وجود طلب خارجي');
    });

    it('sends canAddExternalOrder false when type allows credit and true when it does not', function () {
        $row = OrderTypeRow::query()->where('code', 'takeaway')->firstOrFail();
        $row->update(['allow_partial_payment' => false, 'allow_pay_later' => false]);
        app(OrderTypeCatalog::class)->flush();

        $order = makeOrder($this->shift, $this->user, $this->customer, 'takeaway', 100);

        $this->get(route('orders.manage', $order))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Orders/ManageOrder')
            ->where('canAddExternalOrder', true)
            ->where('credit.allow_partial_payment', false)
            ->where('credit.allow_pay_later', false)
            ->where('credit.require_customer_for_credit', true));

        $row->update(['allow_partial_payment' => true]);
        app(OrderTypeCatalog::class)->flush();

        $this->get(route('orders.manage', $order))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Orders/ManageOrder')
            ->where('canAddExternalOrder', false)
            ->where('credit.allow_partial_payment', true));
    });
});
