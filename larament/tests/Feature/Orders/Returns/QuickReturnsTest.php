<?php

use App\Filament\Pages\Reports\ReturnsReport;
use App\Filament\Widgets\Returns\ReturnedProductsTable;
use App\Filament\Widgets\Returns\ReturnLinesTable;
use App\Filament\Widgets\Returns\ReturnsReportStats;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\OrderTypeRow;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Services\ChannelPerformanceReportService;
use App\Services\DailyComparisonReportService;
use App\Services\Orders\Instant\InstantReturnService;
use App\Services\PeakHoursPerformanceReportService;
use App\Services\ProductsSalesReportService;
use App\Services\Reports\ReturnsAggregates;
use App\Services\ShiftService;
use App\Services\ShiftsReportService;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SafetyNet;

beforeEach(function () {
    ['user' => $this->user, 'shift' => $this->shift] = SafetyNet::till();
    OrderTypeRow::query()->where('code', 'instant_sale')->update(['is_active' => true]);
    $this->shirt = SafetyNet::product(price: 120, cost: 50, stock: 10);
    $this->socks = SafetyNet::product(price: 30, cost: 10, stock: 10);
});

function quickSale(?array $items = null, array $tenders = ['cash' => 300]): Order
{
    $id = test()->postJson(route('pos.instant-sales.store'), [
        'client_uuid' => (string) Str::uuid(),
        'type' => 'instant_sale',
        'items' => $items ?? [
            ['product_id' => test()->shirt->id, 'quantity' => 2],
            ['product_id' => test()->socks->id, 'quantity' => 1],
        ],
        'tenders' => $tenders,
    ])->assertOk()->json('order.id');

    return Order::query()->with('items')->findOrFail($id);
}

function lineOf(Order $order, $product): int
{
    return $order->items->firstWhere('product_id', $product->id)->id;
}

it('parses typed and scanned references', function () {
    expect(InstantReturnService::parseReference('#123'))->toBe(['value' => 123, 'exact' => true])
        ->and(InstantReturnService::parseReference(' ١٢ '))->toBe(['value' => 12, 'exact' => false])
        ->and(InstantReturnService::parseReference('abc'))->toBeNull()
        ->and(InstantReturnService::parseReference('#'))->toBeNull();
});

it('opens a scanned receipt reference directly', function () {
    $order = quickSale();

    $this->getJson(route('pos.instant-returns.lookup', ['number' => '#'.$order->id]))
        ->assertOk()
        ->assertJsonPath('order.id', $order->id)
        ->assertJsonPath('order.reference', '#'.$order->id)
        ->assertJsonPath('order.too_old', false);
});

it('lists receipts of several shifts that share a typed number', function () {
    $old = quickSale();
    $this->shift->update(['end_at' => now(), 'closed' => true]);
    Shift::factory()->active()->create(['user_id' => $this->user->id]);
    $new = quickSale();

    expect((int) $old->order_number)->toBe((int) $new->order_number);

    $response = $this->getJson(route('pos.instant-returns.lookup', ['number' => (string) $new->order_number]))
        ->assertOk();

    $ids = collect($response->json('candidates'))->pluck('id')->all();
    expect($ids)->toContain($old->id)->toContain($new->id)
        ->and($response->json('candidates.0.id'))->toBe($new->id)
        ->and($response->json('candidates.0.current_shift'))->toBeTrue();
});

it('returns a damaged line without moving stock and records it', function () {
    $order = quickSale();

    $this->postJson(route('pos.instant-returns.store'), [
        'order_id' => $order->id,
        'items' => [
            ['order_item_id' => lineOf($order, $this->shirt), 'quantity' => 1, 'restocked' => false],
            ['order_item_id' => lineOf($order, $this->socks), 'quantity' => 1],
        ],
        'tenders' => [],
    ])->assertOk();

    $lines = OrderReturnItem::query()->get()->keyBy('order_item_id');
    expect($lines[lineOf($order, $this->shirt)]->restocked)->toBeFalse()
        ->and($lines[lineOf($order, $this->socks)]->restocked)->toBeTrue()
        ->and(SafetyNet::stock($this->shirt))->toBe(8.0)
        ->and(SafetyNet::stock($this->socks))->toBe(10.0);

    $stats = app(ReturnsAggregates::class)->forShifts([$this->shift->id]);
    // Shirt: damaged, the whole 120 is lost. Socks: 30 refunded, cost 10 back in stock.
    expect($stats['returnsAmount'])->toBe(150.0)
        ->and($stats['damagedQuantity'])->toBe(1.0)
        ->and($stats['restockedQuantity'])->toBe(1.0)
        ->and($stats['returnsProfitImpact'])->toBe(140.0);
});

it('refuses a damaged line without the permission', function () {
    $order = quickSale();
    $cashier = User::factory()->cashier()->create();
    $cashier->givePermissionTo('order.return');

    $this->actingAs($cashier)->postJson(route('pos.instant-returns.store'), [
        'order_id' => $order->id,
        'items' => [['order_item_id' => lineOf($order, $this->shirt), 'quantity' => 1, 'restocked' => false]],
        'tenders' => [],
    ])->assertForbidden();

    expect(OrderReturn::count())->toBe(0);
});

it('needs order.return to look up or return', function () {
    $order = quickSale();
    $user = User::factory()->cashier()->create();
    foreach (Role::all() as $role) {
        $role->revokePermissionTo('order.return');
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($user->fresh())
        ->getJson(route('pos.instant-returns.lookup', ['number' => '#'.$order->id]))
        ->assertForbidden();
});

it('blocks orders older than the max days unless allowed', function () {
    $order = quickSale();
    $order->forceFill(['created_at' => now()->subDays(10)])->save();
    Setting::query()->updateOrCreate(['key' => 'returns_max_days'], ['value' => '7']);

    $cashier = User::factory()->cashier()->create();
    $cashier->givePermissionTo('order.return');

    $this->actingAs($cashier)
        ->getJson(route('pos.instant-returns.lookup', ['number' => '#'.$order->id]))
        ->assertOk()
        ->assertJsonPath('order.too_old', true)
        ->assertJsonPath('order.age_blocked', true);

    $this->actingAs($cashier)->postJson(route('pos.instant-returns.store'), [
        'order_id' => $order->id,
        'items' => [['order_item_id' => lineOf($order, $this->socks), 'quantity' => 1]],
        'tenders' => [],
    ])->assertStatus(422);

    $cashier->givePermissionTo('order.return_any_age');
    $this->actingAs($cashier->fresh())->postJson(route('pos.instant-returns.store'), [
        'order_id' => $order->id,
        'items' => [['order_item_id' => lineOf($order, $this->socks), 'quantity' => 1]],
        'tenders' => [],
    ])->assertOk();
});

it('takes cash refunds out of the expected drawer cash and shows net sales', function () {
    $order = quickSale(); // 270 paid in cash
    $this->postJson(route('pos.instant-returns.store'), [
        'order_id' => $order->id,
        'items' => [['order_item_id' => lineOf($order, $this->shirt), 'quantity' => 1]],
        'tenders' => [],
    ])->assertOk();

    $stats = app(ShiftsReportService::class)->calculateShiftStats($this->shift->fresh());

    expect((float) $stats['availableCash'])->toBe((float) $this->shift->start_cash + 270 - 120)
        ->and($stats['cashRefunds'])->toBe(120.0)
        ->and($stats['netSales'])->toBe(150.0)
        ->and($stats['netProfit'])->toBe(round((float) $stats['profit'] - 70, 2));

    $period = app(ShiftsReportService::class)->calculatePeriodStats(null, null, [$this->shift->id]);
    expect((float) $period['availableCash'])->toBe((float) $stats['availableCash'])
        ->and((float) $period['netSales'])->toBe(150.0);

    $ended = app(ShiftService::class)->endShift((float) $this->shift->start_cash + 150);
    expect((float) $ended->end_cash)->toBe((float) $this->shift->start_cash + 150)
        ->and((float) $ended->losses_amount)->toBe(0.0);
});

it('marks an exchange on the return', function () {
    $order = quickSale();
    $this->postJson(route('pos.instant-returns.store'), [
        'order_id' => $order->id,
        'items' => [['order_item_id' => lineOf($order, $this->socks), 'quantity' => 1]],
        'exchange' => [
            'client_uuid' => (string) Str::uuid(),
            'type' => 'instant_sale',
            'items' => [['product_id' => $this->shirt->id, 'quantity' => 1]],
        ],
        'tenders' => ['cash' => 90],
    ])->assertOk();

    expect(OrderReturn::query()->sole()->exchange_order_id)->not->toBeNull();

    // The refund paid the exchange: cash in = 270 + 90 (+30 credit), out = 30.
    $stats = app(ShiftsReportService::class)->calculateShiftStats($this->shift->fresh());
    expect((float) $stats['availableCash'])->toBe((float) $this->shift->start_cash + 270 + 90);
});

it('keeps sales, returns, quantities and cash right for an exchange to a cheaper item', function () {
    // Sold: 2 shirts (240) + socks (30) = 270 cash.
    $order = quickSale();

    // Bring back one shirt (120), take socks (30): the customer gets 90 back.
    $response = $this->postJson(route('pos.instant-returns.store'), [
        'order_id' => $order->id,
        'items' => [['order_item_id' => lineOf($order, $this->shirt), 'quantity' => 1]],
        'exchange' => [
            'client_uuid' => (string) Str::uuid(),
            'type' => 'instant_sale',
            'items' => [['product_id' => $this->socks->id, 'quantity' => 1]],
        ],
        'tenders' => [],
    ])->assertOk();
    expect((float) $response->json('net'))->toBe(-90.0);

    $stats = app(ShiftsReportService::class)->calculateShiftStats($this->shift->fresh());
    // Gross sales count the replacement sale once; the refund is counted once.
    expect((float) $stats['sales'])->toBe(300.0)
        ->and((float) $stats['returnsAmount'])->toBe(120.0)
        ->and((float) $stats['netSales'])->toBe(180.0)
        // Drawer: 270 in, 90 handed back.
        ->and((float) $stats['availableCash'])->toBe((float) $this->shift->start_cash + 180);

    $today = now()->toDateString();
    $products = app(ProductsSalesReportService::class)->getProductsSalesPerformanceQuery($today, $today)->get();
    expect((float) $products->firstWhere('id', $this->shirt->id)->net_quantity)->toBe(1.0)
        ->and((float) $products->firstWhere('id', $this->shirt->id)->net_sales)->toBe(120.0)
        ->and((float) $products->firstWhere('id', $this->socks->id)->net_quantity)->toBe(2.0)
        ->and(SafetyNet::stock($this->shirt))->toBe(9.0)
        ->and(SafetyNet::stock($this->socks))->toBe(8.0);
});

it('shows returns as gross, refunds and net in the period reports', function () {
    $order = quickSale();
    $this->postJson(route('pos.instant-returns.store'), [
        'order_id' => $order->id,
        'items' => [['order_item_id' => lineOf($order, $this->shirt), 'quantity' => 1, 'restocked' => false]],
        'tenders' => [],
    ])->assertOk();

    $today = now()->toDateString();

    $daily = app(DailyComparisonReportService::class)->getDailyPerformance($today, $today)->first();
    expect($daily->total_sales)->toBe(270.0)
        ->and($daily->total_refunds)->toBe(120.0)
        ->and($daily->net_sales)->toBe(150.0);

    $channel = app(ChannelPerformanceReportService::class)->getChannelPerformanceSummary($today, $today)
        ->firstWhere('type', 'instant_sale');
    expect((float) $channel->total_refunds)->toBe(120.0)
        ->and((float) $channel->net_sales)->toBe(round((float) $channel->total_sales - 120, 2));

    $hour = app(PeakHoursPerformanceReportService::class)->getHourlyPerformance($today, $today)->first();
    expect($hour->total_refunds)->toBe(120.0)
        ->and($hour->net_sales)->toBe(150.0);

    $product = app(ProductsSalesReportService::class)
        ->getProductsSalesPerformanceQuery($today, $today)
        ->get()
        ->firstWhere('id', $this->shirt->id);
    expect((float) $product->returned_quantity)->toBe(1.0)
        ->and((float) $product->damaged_quantity)->toBe(1.0)
        ->and((float) $product->net_quantity)->toBe(1.0)
        ->and((float) $product->net_sales)->toBe(120.0);
});

it('renders the returns report', function () {
    $order = quickSale();
    $this->postJson(route('pos.instant-returns.store'), [
        'order_id' => $order->id,
        'items' => [['order_item_id' => lineOf($order, $this->socks), 'quantity' => 1]],
        'tenders' => [],
    ])->assertOk();

    $this->get(ReturnsReport::getUrl())->assertOk();

    Livewire::test(ReturnLinesTable::class, ['pageFilters' => ['filterType' => 'shifts', 'shifts' => [$this->shift->id]]])
        ->assertOk()
        ->assertCanSeeTableRecords(OrderReturnItem::all());

    Livewire::test(ReturnedProductsTable::class, ['pageFilters' => ['filterType' => 'shifts', 'shifts' => [$this->shift->id]]])
        ->assertOk()
        ->assertSee($this->socks->name);

    Livewire::test(ReturnsReportStats::class, ['pageFilters' => ['filterType' => 'shifts', 'shifts' => [$this->shift->id]]])
        ->assertOk()
        ->assertSee('30.00');
});

it('prints the reference barcode on the receipt', function () {
    $order = quickSale();
    $html = view('print.receipt-reference', ['order' => $order])->render();

    expect($html)->toContain('<svg')->toContain('#'.$order->id);
});
