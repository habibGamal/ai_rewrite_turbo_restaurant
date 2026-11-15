<?php

use App\Core\Orders\GuestStats;
use App\Enums\GuestsMode;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\OrderException;
use App\Filament\Resources\OrderTypeRows\Pages\EditOrderTypeRow;
use App\Models\Order;
use App\Models\OrderTypeRow;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Orders\OrderTypeCatalog;
use App\Services\ChannelPerformanceReportService;
use App\Services\DailyComparisonReportService;
use App\Services\Orders\OrderService;
use App\Services\ShiftsReportService;
use App\Sync\Builders\DaySummaryBuilder;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->shift = Shift::factory()->active()->create(['user_id' => $this->user->id]);
    $this->orders = app(OrderService::class);
    actingAs($this->user);
});

function setGuestsMode(string $code, GuestsMode $mode): void
{
    OrderTypeRow::query()->where('code', $code)->update(['guests_mode' => $mode->value]);
    app(OrderTypeCatalog::class)->flush();
}

function guestOrder(Shift $shift, User $user, string $type, ?int $guests, float $total = 100, OrderStatus $status = OrderStatus::PROCESSING): Order
{
    $product = Product::factory()->create(['price' => $total, 'cost' => 40, 'type' => 'manufactured']);

    $order = Order::factory()->create([
        'type' => $type,
        'status' => $status,
        'payment_status' => PaymentStatus::PENDING,
        'shift_id' => $shift->id,
        'user_id' => $user->id,
        'sub_total' => $total,
        'service' => 0,
        'tax' => 0,
        'discount' => 0,
        'temp_discount_percent' => 0,
        'total' => $total,
        'profit' => $total - 40,
        'guests_count' => $guests,
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

describe('order type setting', function () {
    it('seeds dine-in as optional and every other type as off', function () {
        foreach (OrderTypeRow::query()->get() as $row) {
            $expected = $row->code === 'dine_in' ? GuestsMode::OPTIONAL : GuestsMode::OFF;
            expect($row->guestsMode())->toBe($expected, $row->code);
        }
    });

    it('never asks for guests on instant sale or web orders', function () {
        OrderTypeRow::query()->whereIn('code', ['instant_sale', 'web_delivery', 'web_takeaway'])->update(['guests_mode' => 'required']);

        foreach (OrderTypeRow::query()->whereIn('code', ['instant_sale', 'web_delivery', 'web_takeaway'])->get() as $row) {
            expect($row->guestsMode())->toBe(GuestsMode::OFF, $row->code);
        }
    });

    it('saves the guests mode from the order type panel', function () {
        $row = OrderTypeRow::query()->where('code', 'takeaway')->firstOrFail();

        livewire(EditOrderTypeRow::class, ['record' => $row->getRouteKey()])
            ->fillForm(['guests_mode' => GuestsMode::REQUIRED->value])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($row->fresh()->guests_mode)->toBe(GuestsMode::REQUIRED);
    });
});

describe('cashier', function () {
    it('stores the guest count when opening a dine-in order', function () {
        $this->post(route('orders.store'), ['type' => 'dine_in', 'table_number' => 'T1', 'guests_count' => 4])
            ->assertRedirect();

        expect(Order::latest('id')->first()->guests_count)->toBe(4);
    });

    it('ignores a guest count on a type that does not ask for it', function () {
        $this->post(route('orders.store'), ['type' => 'takeaway', 'guests_count' => 4])->assertRedirect();

        expect(Order::latest('id')->first()->guests_count)->toBeNull();
    });

    it('rejects a guest count out of range', function () {
        $this->post(route('orders.store'), ['type' => 'dine_in', 'table_number' => 'T1', 'guests_count' => 0])
            ->assertSessionHasErrors('guests_count');
        $this->post(route('orders.store'), ['type' => 'dine_in', 'table_number' => 'T1', 'guests_count' => 1000])
            ->assertSessionHasErrors('guests_count');
    });

    it('changes the guest count from the manage order screen', function () {
        $order = guestOrder($this->shift, $this->user, 'dine_in', 2);

        $this->post(route('orders.updateGuests', $order), ['guests_count' => 5])->assertSessionHasNoErrors();
        expect($order->fresh()->guests_count)->toBe(5);

        $this->post(route('orders.updateGuests', $order), ['guests_count' => null])->assertSessionHasNoErrors();
        expect($order->fresh()->guests_count)->toBeNull();
    });

    it('refuses a guest count on a type that does not ask for it', function () {
        $order = guestOrder($this->shift, $this->user, 'takeaway', null);

        $this->post(route('orders.updateGuests', $order), ['guests_count' => 3])->assertSessionHasErrors('guests_count');
        expect($order->fresh()->guests_count)->toBeNull();
    });

    it('refuses to clear the count when it is required', function () {
        setGuestsMode('dine_in', GuestsMode::REQUIRED);
        $order = guestOrder($this->shift, $this->user, 'dine_in', 3);

        expect(fn () => $this->orders->updateGuestsCount($order->id, null))->toThrow(OrderException::class);
        expect($order->fresh()->guests_count)->toBe(3);
    });

    it('sends the guests mode with the order and the tabs', function () {
        $order = guestOrder($this->shift, $this->user, 'dine_in', 2);

        $this->get(route('orders.manage', $order))
            ->assertInertia(fn ($page) => $page->where('order.guests_mode', 'optional')->where('order.guests_count', 2));
    });

    it('clears the count when the order moves to a type that does not ask for it', function () {
        $order = guestOrder($this->shift, $this->user, 'dine_in', 4);

        $this->orders->changeOrderType($order->id, 'takeaway');

        expect($order->fresh()->guests_count)->toBeNull();
    });
});

describe('completion', function () {
    it('blocks completing a required-type order without a count', function () {
        setGuestsMode('takeaway', GuestsMode::REQUIRED);
        $order = guestOrder($this->shift, $this->user, 'takeaway', null);

        expect(fn () => $this->orders->completeOrder($order->id, ['cash' => 100]))
            ->toThrow(OrderException::class, 'يجب إدخال عدد الأفراد قبل إنهاء الطلب');
        expect($order->fresh()->status)->toBe(OrderStatus::PROCESSING)
            ->and($order->payments()->count())->toBe(0);
    });

    it('returns a guests_count error from the complete endpoint', function () {
        setGuestsMode('takeaway', GuestsMode::REQUIRED);
        $order = guestOrder($this->shift, $this->user, 'takeaway', null);

        $this->post(route('orders.complete', $order), ['cash' => 100])->assertSessionHasErrors('guests_count');
    });

    it('completes a required-type order once the count is set', function () {
        setGuestsMode('takeaway', GuestsMode::REQUIRED);
        $order = guestOrder($this->shift, $this->user, 'takeaway', 2);

        expect($this->orders->completeOrder($order->id, ['cash' => 100])->status)->toBe(OrderStatus::COMPLETED);
    });

    it('completes an optional-type order without a count', function () {
        $order = guestOrder($this->shift, $this->user, 'dine_in', null);

        expect($this->orders->completeOrder($order->id, ['cash' => 1000])->status)->toBe(OrderStatus::COMPLETED);
    });
});

describe('reports', function () {
    beforeEach(function () {
        // 300 for 4 guests and 100 for 1 guest; the 500 order has no count and stays out of the average.
        guestOrder($this->shift, $this->user, 'dine_in', 4, 300, OrderStatus::COMPLETED);
        guestOrder($this->shift, $this->user, 'dine_in', 1, 100, OrderStatus::COMPLETED);
        guestOrder($this->shift, $this->user, 'dine_in', null, 500, OrderStatus::COMPLETED);
        guestOrder($this->shift, $this->user, 'dine_in', 9, 900, OrderStatus::CANCELLED);
    });

    it('averages only over orders that carry a count', function () {
        $stats = GuestStats::fromQuery(Order::query()->where('status', OrderStatus::COMPLETED));

        expect($stats->guests)->toBe(5)
            ->and($stats->orders)->toBe(2)
            ->and($stats->sales)->toBe(400.0)
            ->and($stats->averagePerGuest())->toBe(80.0);
    });

    it('adds guests and the average per guest to the shift type stats', function () {
        $dineIn = collect(app(ShiftsReportService::class)->calculateOrderTypeStats($this->shift))->firstWhere('code', 'dine_in');

        expect($dineIn['guests'])->toBe(5)
            ->and($dineIn['avg_per_guest'])->toBe(80.0)
            ->and($dineIn['guests_summary'])->toBe('5 فرد - متوسط الفرد 80.00 جنيه');
    });

    it('leaves the guests summary empty when no order has a count', function () {
        $takeaway = collect(app(ShiftsReportService::class)->calculateOrderTypeStats($this->shift))->firstWhere('code', 'takeaway');

        expect($takeaway['guests'] ?? 0)->toBe(0)
            ->and($takeaway['guests_summary'] ?? null)->toBeNull();
    });

    it('adds guests to the channel and daily comparison reports', function () {
        $from = now()->subDay()->toDateString();
        $to = now()->addDay()->toDateString();

        $channel = app(ChannelPerformanceReportService::class)->getChannelEfficiencyMetrics($from, $to)['guests'];
        $daily = app(DailyComparisonReportService::class)->getPeriodSummary($from, $to)['guests'];

        expect($channel->averagePerGuest())->toBe(80.0)
            ->and($daily->guests)->toBe(5);
    });

    it('syncs the day guest total', function () {
        $this->shift->update(['business_date' => '2026-10-29']);

        $payload = app(DaySummaryBuilder::class)->withKey('2026-10-29')->build('uuid');

        expect($payload['guests_total'])->toBe(5);
    });
});
