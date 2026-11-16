<?php

use App\Enums\SettingKey;
use App\Filament\Resources\OrderReturns\Pages\CreateOrderReturn;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\OrderTypeRow;
use App\Services\Orders\OrderReturnService;
use App\Services\Orders\OrderService;
use App\Services\Orders\ReturnRefundCalculator;
use App\Services\SettingsService;
use Illuminate\Support\Str;
use Tests\Support\SafetyNet;

beforeEach(function () {
    app(SettingsService::class)->set(SettingKey::DINE_IN_SERVICE_CHARGE->value, '0');
    ['user' => $this->user, 'shift' => $this->shift] = SafetyNet::till();
    $this->burger = SafetyNet::product(price: 100, cost: 40, stock: 50);
    $this->fries = SafetyNet::product(price: 50, cost: 10, stock: 50);
    $this->returns = app(OrderReturnService::class);
    $this->calculator = app(ReturnRefundCalculator::class);
});

/**
 * @param  list<array<string, mixed>>  $lines  product_id / quantity / item_discount…
 */
function discountedOrder(array $lines, ?array $orderDiscount = null, string $type = 'takeaway', ?string $table = null): Order
{
    $order = SafetyNet::open(test()->shift, test()->user, $type, $table);
    app(OrderService::class)->updateOrderItems($order->id, $lines);
    if ($orderDiscount !== null) {
        app(OrderService::class)->applyDiscount($order->id, $orderDiscount[0], $orderDiscount[1]);
    }

    $order = $order->fresh();

    return app(OrderService::class)->completeOrder($order->id, ['cash' => (float) $order->total]);
}

function itemId(Order $order, $product): int
{
    return $order->items()->where('product_id', $product->id)->value('id');
}

/** Return with the server-computed refund (refund_amount left out), paid in cash. */
function returnUnits(Order $order, array $lines): OrderReturn
{
    $service = test()->returns;
    $total = 0.0;
    foreach ($lines as [$itemId, $quantity]) {
        $refund = $service->itemRefund($order->fresh(), $itemId, $quantity);
        $total += $refund;
    }

    return $service->processReturn(
        $order->fresh(),
        array_map(fn (array $line) => ['order_item_id' => $line[0], 'quantity' => $line[1]], $lines),
        'test',
        $total > 0 ? [['method' => 'cash', 'amount' => round($total, 2)]] : [],
        test()->shift->id,
    );
}

it('takes an item discount off that item only', function () {
    $order = discountedOrder([
        ['product_id' => $this->burger->id, 'quantity' => 2, 'item_discount' => 40],
        ['product_id' => $this->fries->id, 'quantity' => 1],
    ]);
    expect((float) $order->total)->toBe(210.0);

    $lines = $this->calculator->lines($order);
    $burger = $lines[itemId($order, $this->burger)];
    $fries = $lines[itemId($order, $this->fries)];

    expect($burger['refundable'])->toBe(160.0)
        ->and($burger['unit_refund'])->toBe(80.0)
        ->and($burger['item_discount'])->toBe(40.0)
        ->and($burger['order_discount'])->toBe(0.0)
        ->and($fries['refundable'])->toBe(50.0)
        ->and(ReturnRefundCalculator::explain($burger))->toBe('100.00 × 2 = 200.00 − خصم الصنف 40.00 = 160.00 (الوحدة 80.00)');

    $first = returnUnits($order, [[itemId($order, $this->burger), 1]]);
    $second = returnUnits($order, [[itemId($order, $this->fries), 1]]);

    expect((float) $first->total_refund)->toBe(80.0)
        ->and((float) $second->total_refund)->toBe(50.0);
});

it('spreads an order discount over the lines by their value', function () {
    $order = discountedOrder([
        ['product_id' => $this->burger->id, 'quantity' => 2],
        ['product_id' => $this->fries->id, 'quantity' => 1],
    ], [25, 'fixed']);
    expect((float) $order->total)->toBe(225.0);

    $lines = $this->calculator->lines($order);
    expect($lines[itemId($order, $this->burger)]['refundable'])->toBe(180.0)
        ->and($lines[itemId($order, $this->burger)]['order_discount'])->toBe(20.0)
        ->and($lines[itemId($order, $this->fries)]['refundable'])->toBe(45.0);

    expect((float) returnUnits($order, [[itemId($order, $this->burger), 1]])->total_refund)->toBe(90.0)
        ->and((float) returnUnits($order, [[itemId($order, $this->burger), 1], [itemId($order, $this->fries), 1]])->total_refund)->toBe(135.0);

    // Everything returned refunds exactly what was paid.
    expect((float) $order->fresh()->returns()->sum('total_refund'))->toBe(225.0);
});

it('spreads a percent order discount the same way', function () {
    $order = discountedOrder([
        ['product_id' => $this->burger->id, 'quantity' => 2],
        ['product_id' => $this->fries->id, 'quantity' => 2],
    ], [10, 'percent']);
    expect((float) $order->total)->toBe(270.0);

    expect($this->returns->itemRefund($order, itemId($order, $this->burger), 1))->toBe(90.0)
        ->and($this->returns->itemRefund($order, itemId($order, $this->fries), 2))->toBe(90.0);
});

it('handles an item discount and an order discount on the same order', function () {
    // The POS clears one when the other is set, so build the stored state by hand.
    $order = discountedOrder([
        ['product_id' => $this->burger->id, 'quantity' => 2],
        ['product_id' => $this->fries->id, 'quantity' => 1],
    ]);
    $order->items()->where('product_id', $this->burger->id)->update(['item_discount' => 20]);
    // 20 on the burger line + 23 for the whole order
    $order->forceFill(['discount' => 43, 'total' => 207])->save();
    $order = $order->fresh();

    $lines = $this->calculator->lines($order);
    $burger = $lines[itemId($order, $this->burger)];
    $fries = $lines[itemId($order, $this->fries)];

    // Order discount 23 split by value after item discounts: 180 / 230 and 50 / 230.
    expect($burger['item_discount'])->toBe(20.0)
        ->and($burger['order_discount'])->toBe(18.0)
        ->and($burger['refundable'])->toBe(162.0)
        ->and($fries['order_discount'])->toBe(5.0)
        ->and($fries['refundable'])->toBe(45.0)
        ->and($burger['refundable'] + $fries['refundable'])->toBe(207.0);
});

it('refunds partial quantities so the parts add up to the line', function () {
    $order = discountedOrder([
        ['product_id' => $this->burger->id, 'quantity' => 3],
    ], [10, 'fixed']);
    expect((float) $order->total)->toBe(290.0);

    $line = itemId($order, $this->burger);
    $refunds = [
        (float) returnUnits($order, [[$line, 1]])->total_refund,
        (float) returnUnits($order, [[$line, 1]])->total_refund,
        (float) returnUnits($order, [[$line, 1]])->total_refund,
    ];

    expect($refunds)->toBe([96.67, 96.67, 96.66])
        ->and(round(array_sum($refunds), 2))->toBe(290.0);
});

it('refunds fractional (weighed) quantities by share', function () {
    $order = discountedOrder([
        ['product_id' => $this->burger->id, 'quantity' => 2.5],
    ], [25, 'fixed']);
    expect((float) $order->total)->toBe(225.0);

    expect($this->returns->itemRefund($order, itemId($order, $this->burger), 0.5))->toBe(45.0)
        ->and($this->returns->itemRefund($order, itemId($order, $this->burger), 2.5))->toBe(225.0);
});

it('refunds the share of a percentage service charge on dine-in', function () {
    app(SettingsService::class)->set(SettingKey::DINE_IN_SERVICE_CHARGE->value, '0.12');
    $order = discountedOrder([
        ['product_id' => $this->burger->id, 'quantity' => 2],
        ['product_id' => $this->fries->id, 'quantity' => 1],
    ], [25, 'fixed'], 'dine_in', '5');
    // 250 + 30 service − 25
    expect((float) $order->service)->toBe(30.0)->and((float) $order->total)->toBe(255.0);

    $lines = $this->calculator->lines($order);
    $burger = $lines[itemId($order, $this->burger)];
    expect($burger['service'])->toBe(24.0)
        ->and($burger['refundable'])->toBe(204.0)
        ->and($lines[itemId($order, $this->fries)]['refundable'])->toBe(51.0)
        ->and(ReturnRefundCalculator::explain($burger))->toBe('100.00 × 2 = 200.00 − نصيبه من خصم الفاتورة 20.00 + نصيبه من الخدمة 24.00 = 204.00 (الوحدة 102.00)');
})->skip(fn () => ! OrderTypeRow::query()->where('code', 'dine_in')->exists(), 'no dine-in type');

it('does not refund a flat delivery fee with the items', function () {
    $order = discountedOrder([
        ['product_id' => $this->burger->id, 'quantity' => 1],
    ]);
    // A delivery order: 100 items + 30 delivery fee.
    $order->forceFill(['type' => 'delivery', 'service' => 30, 'total' => 130])->save();

    expect($this->calculator->lines($order->fresh())[itemId($order, $this->burger)]['refundable'])->toBe(100.0);
});

it('shares the rounding of the total over the lines', function () {
    $cheap = SafetyNet::product(price: 33.5, cost: 10, stock: 10);
    $order = discountedOrder([
        ['product_id' => $cheap->id, 'quantity' => 1],
        ['product_id' => $this->fries->id, 'quantity' => 1],
    ]);
    // 83.50 rounded up to 84
    expect((float) $order->total)->toBe(84.0);

    $lines = $this->calculator->lines($order);
    expect($lines[itemId($order, $cheap)]['refundable'])->toBe(33.7)
        ->and($lines[itemId($order, $this->fries)]['refundable'])->toBe(50.3);
});

it('leaves custom fees and equipment out of the item refunds', function () {
    $order = discountedOrder([
        ['product_id' => $this->burger->id, 'quantity' => 1],
    ]);
    $order->forceFill(['fees_total' => 30, 'total' => 130])->save();

    expect($this->calculator->lines($order->fresh())[itemId($order, $this->burger)]['refundable'])->toBe(100.0);
});

it('refuses an item refund above what was paid after discounts', function () {
    $order = discountedOrder([
        ['product_id' => $this->burger->id, 'quantity' => 2, 'item_discount' => 40],
    ]);

    expect(fn () => $this->returns->processReturn(
        $order->fresh(),
        [['order_item_id' => itemId($order, $this->burger), 'quantity' => 1, 'refund_amount' => 100]],
        'test',
        [['method' => 'cash', 'amount' => 100]],
        $this->shift->id,
    ))->toThrow(Exception::class, 'أكبر من المستحق');

    // A smaller refund (goodwill) is allowed.
    $return = $this->returns->processReturn(
        $order->fresh(),
        [['order_item_id' => itemId($order, $this->burger), 'quantity' => 1, 'refund_amount' => 70]],
        'test',
        [['method' => 'cash', 'amount' => 70]],
        $this->shift->id,
    );
    expect((float) $return->total_refund)->toBe(70.0);
});

it('prices instant-sale returns with the item discount on its own line', function () {
    OrderTypeRow::query()->where('code', 'instant_sale')->update(['is_active' => true]);

    $id = $this->postJson(route('pos.instant-sales.store'), [
        'client_uuid' => (string) Str::uuid(),
        'type' => 'instant_sale',
        'items' => [
            ['product_id' => $this->burger->id, 'quantity' => 2, 'item_discount' => 40],
            ['product_id' => $this->fries->id, 'quantity' => 1],
        ],
        'tenders' => ['cash' => 300],
    ])->assertOk()->json('order.id');
    $order = Order::query()->findOrFail($id);
    expect((float) $order->total)->toBe(210.0);

    $lookup = $this->getJson(route('pos.instant-returns.lookup', ['number' => '#'.$order->id]))->assertOk();
    $burger = collect($lookup->json('order.items'))->firstWhere('product_id', $this->burger->id);
    $fries = collect($lookup->json('order.items'))->firstWhere('product_id', $this->fries->id);
    expect((float) $burger['unit_refund'])->toBe(80.0)
        ->and((float) $burger['refundable'])->toBe(160.0)
        ->and($burger['discounted'])->toBeTrue()
        ->and($burger['explanation'])->toContain('خصم الصنف 40.00')
        ->and((float) $fries['unit_refund'])->toBe(50.0);

    $this->postJson(route('pos.instant-returns.store'), [
        'order_id' => $order->id,
        'items' => [['order_item_id' => $burger['id'], 'quantity' => 1], ['order_item_id' => $fries['id'], 'quantity' => 1]],
        'tenders' => [],
    ])->assertOk();

    expect((float) OrderReturn::query()->sole()->total_refund)->toBe(130.0);
});

it('fills the admin return form with the discounted unit refund and saves the server amount', function () {
    $order = discountedOrder([
        ['product_id' => $this->burger->id, 'quantity' => 2],
        ['product_id' => $this->fries->id, 'quantity' => 1],
    ], [25, 'fixed']);

    $page = Pest\Livewire\livewire(CreateOrderReturn::class)
        ->fillForm(['order_id' => $order->id]);

    $items = collect($page->get('data.return_items'));
    $burgerKey = $items->search(fn ($row) => $row['order_item_id'] === itemId($order, $this->burger));
    expect((float) $items[$burgerKey]['unit_price'])->toBe(90.0)
        ->and($items[$burgerKey]['explanation'])->toContain('نصيبه من خصم الفاتورة 20.00');

    $distributionKey = array_key_first($page->get('data.refund_distribution'));
    $page->fillForm([
        "return_items.{$burgerKey}.quantity" => 1,
        "refund_distribution.{$distributionKey}.amount" => 90,
        'reason' => 'test',
    ])->call('create')->assertHasNoFormErrors();

    expect((float) $order->fresh()->returns()->sole()->total_refund)->toBe(90.0);
});
