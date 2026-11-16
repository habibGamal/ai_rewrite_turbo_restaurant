<?php

use App\Filament\Pages\Reports\CurrentShiftReport;
use App\Filament\Pages\Reports\PeriodShiftReport;
use App\Filament\Widgets\CurrentShiftMoneyInfoStats;
use App\Filament\Widgets\PeriodShiftMoneyInfoStats;
use App\Filament\Widgets\Returns\CurrentShiftReturnsTable;
use App\Filament\Widgets\Returns\ShiftReturnsTable;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Shift;
use App\Services\Orders\OrderReturnService;
use App\Services\Orders\OrderService;
use Livewire\Livewire;
use Tests\Support\SafetyNet;

beforeEach(function () {
    ['user' => $this->user, 'shift' => $this->shift] = SafetyNet::till();
    $this->burger = SafetyNet::product(price: 100, cost: 40, stock: 50);
});

function soldAndReturned(Shift $shift, $user, $product, int $guests = 0): OrderReturn
{
    $order = SafetyNet::open($shift, $user);
    SafetyNet::save($order, [[$product, 2]]);
    if ($guests > 0) {
        Order::query()->whereKey($order->id)->update(['guests_count' => $guests]);
    }
    $order = app(OrderService::class)->completeOrder($order->id, ['cash' => 200]);

    return app(OrderReturnService::class)->processReturn(
        $order->fresh(),
        [['order_item_id' => $order->items()->value('id'), 'quantity' => 1]],
        'مكسور',
        [['method' => 'cash', 'amount' => 100]],
        $shift->id,
    );
}

it('lists the open shift returns on the current shift report', function () {
    $return = soldAndReturned($this->shift, $this->user, $this->burger);

    $this->get(CurrentShiftReport::getUrl())->assertOk()->assertSee(ShiftReturnsTable::ANCHOR);

    Livewire::test(CurrentShiftReturnsTable::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$return])
        ->assertSee($this->burger->name)
        ->assertSee('مكسور');
});

it('lists the returns of the picked shifts on the period report', function () {
    $return = soldAndReturned($this->shift, $this->user, $this->burger);
    $other = Shift::factory()->create(['user_id' => $this->user->id, 'closed' => true, 'end_at' => now()]);
    $hidden = OrderReturn::query()->create([
        'order_id' => $return->order_id, 'user_id' => $this->user->id, 'shift_id' => $other->id,
        'total_refund' => 0, 'reason' => 'x', 'reverse_stock' => false,
    ]);

    $this->get(PeriodShiftReport::getUrl())->assertOk();

    Livewire::test(ShiftReturnsTable::class, ['pageFilters' => ['filterType' => 'shifts', 'shifts' => [$this->shift->id]]])
        ->assertOk()
        ->assertCanSeeTableRecords([$return])
        ->assertCanNotSeeTableRecords([$hidden]);
});

it('makes the returns card open the returns table', function () {
    soldAndReturned($this->shift, $this->user, $this->burger);

    Livewire::test(CurrentShiftMoneyInfoStats::class)
        ->assertSee("getElementById('".ShiftReturnsTable::ANCHOR."')", false);
});

it('shows the guest count and average per guest when orders carry one', function () {
    soldAndReturned($this->shift, $this->user, $this->burger, guests: 4);

    // 200 over 4 guests
    Livewire::test(PeriodShiftMoneyInfoStats::class, ['pageFilters' => ['filterType' => 'shifts', 'shifts' => [$this->shift->id]]])
        ->assertSee('متوسط الفرد')
        ->assertSee('50.00 جنيه')
        ->assertSee('4 فرد في 1 طلب');

    Livewire::test(CurrentShiftMoneyInfoStats::class)->assertSee('متوسط الفرد');
});

it('hides the guest average when no order has a guest count', function () {
    soldAndReturned($this->shift, $this->user, $this->burger);

    Livewire::test(PeriodShiftMoneyInfoStats::class, ['pageFilters' => ['filterType' => 'shifts', 'shifts' => [$this->shift->id]]])
        ->assertDontSee('متوسط الفرد');
});
