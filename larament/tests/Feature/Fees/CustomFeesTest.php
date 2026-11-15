<?php

use App\Core\Events\OrderChargesChanged;
use App\Enums\AuditAction;
use App\Enums\OptionalFeature;
use App\Enums\OrderStatus;
use App\Enums\SettingKey;
use App\Exceptions\OrderException;
use App\Filament\Pages\Reports\CustomFeesReport;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderFee;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Orders\TabPermissions;
use App\Services\CustomFeesReportService;
use App\Services\FeatureService;
use App\Services\Orders\OrderFeeService;
use App\Services\SettingsService;
use App\Services\ShiftsReportService;
use App\Sync\Builders\DaySummaryBuilder;
use App\Sync\Builders\OrderBuilder;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    app(FeatureService::class)->enable(OptionalFeature::CUSTOM_FEES);
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    $this->shift = Shift::factory()->active()->create(['user_id' => $this->admin->id, 'business_date' => now()->toDateString()]);
    $this->product = Product::factory()->create(['price' => 100, 'cost' => 40]);
});

function feeOrder(array $attributes = []): Order
{
    $order = Order::factory()->create([
        'user_id' => test()->admin->id,
        'shift_id' => test()->shift->id,
        'type' => 'takeaway',
        'status' => OrderStatus::PROCESSING,
        'discount' => 0,
        'temp_discount_percent' => 0,
        ...$attributes,
    ]);
    $order->items()->create([
        'product_id' => test()->product->id,
        'quantity' => 1,
        'price' => 100,
        'cost' => 40,
        'total' => 100,
    ]);

    return $order;
}

it('adds fee lines from the till and adds them to the bill after service and discount', function () {
    app(SettingsService::class)->set(SettingKey::DINE_IN_SERVICE_CHARGE->value, '0.10');
    $order = feeOrder(['type' => 'dine_in', 'temp_discount_percent' => 10]);

    $this->post(route('orders.fees.store', $order), ['fees' => [
        ['name' => '  رسوم دخول   تورتة ', 'amount' => 50],
        ['name' => 'رسوم حجز', 'amount' => 25.5],
    ]])->assertRedirect()->assertSessionHas('success');

    $order->refresh();
    // items 100 + service 10 (items only) − discount 10 (items only) + fees 75.5 = 175.5 → ceil 176
    expect($order->fees()->pluck('name')->all())->toBe(['رسوم دخول تورتة', 'رسوم حجز'])
        ->and((float) $order->fees_total)->toBe(75.5)
        ->and((float) $order->service)->toBe(10.0)
        ->and((float) $order->discount)->toBe(10.0)
        ->and((float) $order->total)->toBe(176.0)
        ->and((float) $order->profit)->toBe(136.0);
});

it('keeps fees when the items are saved again', function () {
    $order = feeOrder();
    app(OrderFeeService::class)->add($order->id, [['name' => 'رسوم', 'amount' => 30]]);

    $this->post(route('orders.save', $order), ['items' => [[
        'product_id' => $this->product->id, 'quantity' => 2, 'price' => 100, 'notes' => null,
    ]]])->assertRedirect();

    expect((float) $order->fresh()->total)->toBe(230.0);
});

it('dispatches OrderChargesChanged with the fees kind', function () {
    Event::fake([OrderChargesChanged::class]);
    $order = feeOrder();

    app(OrderFeeService::class)->add($order->id, [['name' => 'رسوم', 'amount' => 10]]);

    Event::assertDispatched(OrderChargesChanged::class, fn (OrderChargesChanged $event) => $event->kind === OrderChargesChanged::FEES && $event->order->is($order));
});

it('validates name and amount', function () {
    $order = feeOrder();

    $this->post(route('orders.fees.store', $order), ['fees' => [['name' => '', 'amount' => 10]]])->assertSessionHasErrors('fees.0.name');
    $this->post(route('orders.fees.store', $order), ['fees' => [['name' => 'x', 'amount' => 0]]])->assertSessionHasErrors('fees.0.amount');
    $this->post(route('orders.fees.store', $order), ['fees' => [['name' => str_repeat('a', 101), 'amount' => 5]]])->assertSessionHasErrors('fees.0.name');

    expect($order->fees()->count())->toBe(0);
});

it('refuses fees on completed orders and on instant sales', function () {
    $completed = feeOrder(['status' => OrderStatus::COMPLETED]);
    $instant = feeOrder(['type' => 'instant_sale']);
    $service = app(OrderFeeService::class);

    expect(fn () => $service->add($completed->id, [['name' => 'رسوم', 'amount' => 10]]))->toThrow(OrderException::class)
        ->and(fn () => $service->add($instant->id, [['name' => 'رسوم', 'amount' => 10]]))->toThrow(OrderException::class)
        ->and(OrderFee::count())->toBe(0);
});

it('refuses fees and hides the permissions while the feature is off', function () {
    $order = feeOrder();
    app(FeatureService::class)->disable(OptionalFeature::CUSTOM_FEES);

    expect(fn () => app(OrderFeeService::class)->add($order->id, [['name' => 'رسوم', 'amount' => 10]]))->toThrow(OrderException::class)
        ->and($this->admin->fresh()->can('order.add_fees'))->toBeFalse()
        ->and(CustomFeesReport::canAccess())->toBeFalse();

    $this->post(route('orders.fees.store', $order), ['fees' => [['name' => 'رسوم', 'amount' => 10]]])->assertForbidden();
    $this->get(route('orders.manage', $order))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('canAddFees', false)
        ->where('feeSuggestions', []));
});

it('lets a cashier add fees but only a permitted user remove them, with an audit row', function () {
    $order = feeOrder();
    $cashier = User::factory()->cashier()->create();
    $cashier->givePermissionTo(Permission::firstOrCreate(['name' => TabPermissions::forTypeCode('takeaway'), 'guard_name' => 'web']));
    $this->actingAs($cashier);

    $this->post(route('orders.fees.store', $order), ['fees' => [['name' => 'رسوم', 'amount' => 40]]])->assertRedirect()->assertSessionHas('success');
    $fee = $order->fees()->firstOrFail();
    $this->delete(route('orders.fees.destroy', [$order, $fee->id]))->assertForbidden();

    $this->actingAs($this->admin);
    $this->delete(route('orders.fees.destroy', [$order, $fee->id]))->assertRedirect()->assertSessionHas('success');

    $audit = AuditLog::query()->latest('id')->firstOrFail();
    expect(OrderFee::count())->toBe(0)
        ->and((float) $order->fresh()->fees_total)->toBe(0.0)
        ->and((float) $order->fresh()->total)->toBe(100.0)
        ->and($audit->action)->toBe(AuditAction::DELETED)
        ->and($audit->context)->toBe('order_fee')
        ->and($audit->shift_id)->toBe($this->shift->id)
        ->and((float) $audit->old_values['amount'])->toBe(40.0)
        ->and($audit->old_values['name'])->toBe('رسوم');
});

it('offers earlier fee names most used first, grouping spelling variants', function () {
    $service = app(OrderFeeService::class);
    $service->add(feeOrder()->id, [['name' => 'رسوم حجز', 'amount' => 10]]);
    $service->add(feeOrder()->id, [['name' => 'رسوم دخول تورتة', 'amount' => 50], ['name' => 'رسوم دخول  تورتة ', 'amount' => 50]]);

    expect($service->suggestions())->toBe(['رسوم دخول تورتة', 'رسوم حجز']);

    $order = feeOrder();
    $this->get(route('orders.manage', $order))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('canAddFees', true)
        ->where('feeSuggestions', ['رسوم دخول تورتة', 'رسوم حجز']));
});

it('sends fee lines to the order screen and to sync', function () {
    $order = feeOrder();
    app(OrderFeeService::class)->add($order->id, [['name' => 'رسوم حجز', 'amount' => 25]]);

    $this->get(route('orders.manage', $order))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('order.fees', 1)
        ->where('order.fees.0.name', 'رسوم حجز')
        ->missing('order.fees.0.name_key'));

    $order->update(['status' => OrderStatus::COMPLETED]);
    $document = app(OrderBuilder::class)->build($order->uuid);
    $summary = app(DaySummaryBuilder::class)->withKey($this->shift->business_date)->build('x');

    expect($document['fees_total'])->toBe('25.00')
        ->and($document['fees'][0]['name'])->toBe('رسوم حجز')
        ->and($document['fees'][0]['amount'])->toBe('25.00')
        ->and($summary['fees_total'])->toBe('25.00');
});

it('counts fees of completed orders in shift stats and the fees report', function () {
    $service = app(OrderFeeService::class);
    $first = feeOrder();
    $second = feeOrder();
    $cancelled = feeOrder();
    $service->add($first->id, [['name' => 'رسوم دخول تورتة', 'amount' => 50]]);
    $service->add($second->id, [['name' => 'رسوم دخول  تورتة', 'amount' => 30], ['name' => 'رسوم حجز', 'amount' => 20]]);
    $service->add($cancelled->id, [['name' => 'رسوم حجز', 'amount' => 99]]);
    Order::whereKey([$first->id, $second->id])->update(['status' => OrderStatus::COMPLETED]);
    $cancelled->update(['status' => OrderStatus::CANCELLED]);

    $shiftStats = app(ShiftsReportService::class)->calculateShiftStats($this->shift);
    $periodStats = app(ShiftsReportService::class)->calculatePeriodStats(null, null, [$this->shift->id]);
    $summary = app(CustomFeesReportService::class)->summaryByName([$this->shift->id]);

    expect($shiftStats['fees'])->toBe(100.0)
        ->and($periodStats['fees'])->toBe(100.0)
        ->and(array_values($summary))->toBe([
            ['name' => 'رسوم دخول تورتة', 'count' => 2, 'amount' => 80.0, 'average' => 40.0, 'share' => 80.0],
            ['name' => 'رسوم حجز', 'count' => 1, 'amount' => 20.0, 'average' => 20.0, 'share' => 20.0],
        ]);
});

it('renders the fees report for an administrator', function () {
    app(OrderFeeService::class)->add(feeOrder()->id, [['name' => 'رسوم حجز', 'amount' => 20]]);

    $this->get(CustomFeesReport::getUrl())->assertOk()->assertSee('تقرير الرسوم الإضافية');
});

it('refuses to switch fees off while an open order has fee lines', function () {
    $order = feeOrder();
    app(OrderFeeService::class)->add($order->id, [['name' => 'رسوم', 'amount' => 10]]);

    expect(app(FeatureService::class)->checkDisableBlockers(OptionalFeature::CUSTOM_FEES))->toHaveCount(1);

    $order->update(['status' => OrderStatus::COMPLETED]);

    expect(app(FeatureService::class)->checkDisableBlockers(OptionalFeature::CUSTOM_FEES))->toBe([]);
});
