<?php

use App\Enums\OrderStatus;
use App\Exceptions\OrderException;
use App\Filament\Pages\ExternalSellerAccounts;
use App\Filament\Resources\ExternalSellers\ExternalSellerResource;
use App\Models\ExternalSeller;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Orders\GuestBill;
use App\Orders\Tenders;
use App\Services\InventoryDailyAggregationService;
use App\Services\Orders\ExternalOrderService;
use App\Services\Orders\ExternalSellerSettlementService;
use App\Services\Orders\OrderService;
use App\Services\ShiftsReportService;
use App\Sync\Builders\OrderBuilder;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;

it('splits the guest bill while keeping restaurant money separate', function () {
    $user = User::factory()->admin()->create();
    $shift = Shift::factory()->active()->create(['user_id' => $user->id]);
    $order = Order::factory()->create(['user_id' => $user->id, 'shift_id' => $shift->id, 'type' => 'takeaway', 'status' => OrderStatus::PROCESSING, 'total' => 100]);
    $seller = ExternalSeller::create(['name' => 'عربة الصوص', 'is_active' => true]);
    $this->actingAs($user);

    app(ExternalOrderService::class)->save($order->id, $seller->id, [['name' => 'صوص', 'quantity' => 1, 'price' => 40]]);
    $bill = app(GuestBill::class);
    $split = $bill->split($order, Tenders::fromArray(['card' => 100, 'cash' => 40]));

    expect($bill->due($order))->toBe(140.0)
        ->and($split['restaurant']->amounts())->toBe(['card' => 100.0])
        ->and($split['collections'][0]['method'])->toBe('cash')
        ->and($split['collections'][0]['amount'])->toBe(40.0)
        ->and((float) $order->fresh()->total)->toBe(100.0);

    expect(fn () => $bill->split($order, Tenders::fromArray(['cash' => 80])))->toThrow(OrderException::class);
    $change = $bill->split($order, Tenders::fromArray(['cash' => 150]));
    expect($change['restaurant']->amounts())->toBe(['cash' => 100.0])
        ->and($change['collections'][0]['amount'])->toBe(40.0);
    expect(fn () => $bill->split($order, Tenders::fromArray(['cash' => 41, 'card' => 100])))->toThrow(OrderException::class);
});

it('only settles collected money during an open shift', function () {
    $user = User::factory()->admin()->create();
    $shift = Shift::factory()->active()->create(['user_id' => $user->id]);
    $seller = ExternalSeller::create(['name' => 'عربة الصوص', 'is_active' => true]);
    $order = Order::factory()->create(['user_id' => $user->id, 'shift_id' => $shift->id]);
    $external = $seller->orders()->create(['host_order_id' => $order->id, 'seller_name' => $seller->name, 'status' => 'pending', 'total' => 40]);
    $external->collections()->create(['shift_id' => $shift->id, 'user_id' => $user->id, 'method' => 'cash', 'amount' => 40]);
    $this->actingAs($user);
    $service = app(ExternalSellerSettlementService::class);

    expect(fn () => $service->pay($seller->id, 41, null))->toThrow(OrderException::class);
    $service->pay($seller->id, 40, null);
    expect($service->payable($seller))->toBe(0.0);
    $shift->update(['closed' => true, 'end_at' => now()]);
    expect(fn () => $service->pay($seller->id, 1, null))->toThrow(OrderException::class, 'يجب فتح شيفت');
});

it('refuses an external order on a company order', function () {
    $user = User::factory()->admin()->create();
    $shift = Shift::factory()->active()->create(['user_id' => $user->id]);
    $order = Order::factory()->create(['user_id' => $user->id, 'shift_id' => $shift->id, 'type' => 'companies', 'status' => OrderStatus::PROCESSING]);
    $seller = ExternalSeller::create(['name' => 'عربة الصوص', 'is_active' => true]);

    expect(fn () => app(ExternalOrderService::class)->save($order->id, $seller->id, [['name' => 'صوص', 'quantity' => 1, 'price' => 40]]))
        ->toThrow(OrderException::class);
});

it('allocates seller shares in order with cash before card', function () {
    $user = User::factory()->admin()->create();
    $shift = Shift::factory()->active()->create(['user_id' => $user->id]);
    $order = Order::factory()->create(['user_id' => $user->id, 'shift_id' => $shift->id, 'type' => 'takeaway', 'status' => OrderStatus::PROCESSING, 'total' => 100]);
    $first = ExternalSeller::create(['name' => 'البائع الأول', 'is_active' => true]);
    $second = ExternalSeller::create(['name' => 'البائع الثاني', 'is_active' => true]);
    $service = app(ExternalOrderService::class);
    $firstOrder = $service->save($order->id, $first->id, [['name' => 'صوص', 'quantity' => 1, 'price' => 40]]);
    $secondOrder = $service->save($order->id, $second->id, [['name' => 'مشروب', 'quantity' => 1, 'price' => 30]]);

    $split = app(GuestBill::class)->split($order, Tenders::fromArray(['cash' => 50, 'card' => 120]));

    expect($split['restaurant']->amounts())->toBe(['card' => 100.0])
        ->and($split['collections'])->toBe([
            ['external_order_id' => $firstOrder->id, 'method' => 'cash', 'amount' => 40.0],
            ['external_order_id' => $secondOrder->id, 'method' => 'cash', 'amount' => 10.0],
            ['external_order_id' => $secondOrder->id, 'method' => 'card', 'amount' => 20.0],
        ]);
});

it('records only the restaurant amount as a payment when completing a guest bill', function () {
    $user = User::factory()->admin()->create();
    $shift = Shift::factory()->active()->create(['user_id' => $user->id]);
    $product = Product::factory()->create(['price' => 100, 'cost' => 40, 'type' => 'manufactured']);
    $order = Order::factory()->create(['user_id' => $user->id, 'shift_id' => $shift->id, 'type' => 'takeaway', 'status' => OrderStatus::PROCESSING, 'discount' => 0, 'temp_discount_percent' => 0]);
    $order->items()->create(['product_id' => $product->id, 'quantity' => 1, 'price' => 100, 'cost' => 40, 'total' => 100]);
    $seller = ExternalSeller::create(['name' => 'عربة الصوص', 'is_active' => true]);
    $this->actingAs($user);
    $external = app(ExternalOrderService::class)->save($order->id, $seller->id, [['name' => 'صوص', 'quantity' => 1, 'price' => 40]]);

    expect(fn () => app(OrderService::class)->completeOrder($order->id, ['cash' => 80]))->toThrow(OrderException::class);
    $completed = app(OrderService::class)->completeOrder($order->id, ['cash' => 150]);

    expect((float) $completed->total)->toBe(100.0)
        ->and((float) $completed->profit)->toBe(60.0)
        ->and((float) $completed->payments()->sum('amount'))->toBe(100.0)
        ->and((float) $external->collections()->sum('amount'))->toBe(40.0);
});

it('assigns cash to the external seller when a guest pays with card and cash', function () {
    $user = User::factory()->admin()->create();
    $shift = Shift::factory()->active()->create(['user_id' => $user->id]);
    $product = Product::factory()->create(['price' => 100, 'cost' => 40, 'type' => 'manufactured']);
    $order = Order::factory()->create(['user_id' => $user->id, 'shift_id' => $shift->id, 'type' => 'takeaway', 'status' => OrderStatus::PROCESSING, 'discount' => 0, 'temp_discount_percent' => 0]);
    $order->items()->create(['product_id' => $product->id, 'quantity' => 1, 'price' => 100, 'cost' => 40, 'total' => 100]);
    $seller = ExternalSeller::create(['name' => 'عربة الصوص', 'is_active' => true]);
    $this->actingAs($user);
    $external = app(ExternalOrderService::class)->save($order->id, $seller->id, [['name' => 'صوص', 'quantity' => 1, 'price' => 40]]);

    $completed = app(OrderService::class)->completeOrder($order->id, ['card' => 100, 'cash' => 40]);

    expect((float) $completed->payments()->where('method', 'card')->sum('amount'))->toBe(100.0)
        ->and((float) $external->collections()->where('method', 'cash')->sum('amount'))->toBe(40.0)
        ->and((float) $completed->total)->toBe(100.0)
        ->and((float) $completed->profit)->toBe(60.0);
});

it('renders external lines on both receipt forms and accounts for cash and settlement', function () {
    $user = User::factory()->admin()->create();
    $shift = Shift::factory()->active()->create(['user_id' => $user->id, 'start_cash' => 10]);
    $order = Order::factory()->create(['user_id' => $user->id, 'shift_id' => $shift->id, 'type' => 'takeaway', 'status' => OrderStatus::PROCESSING, 'total' => 100]);
    $seller = ExternalSeller::create(['name' => 'عربة الصوص', 'is_active' => true]);
    $this->actingAs($user);
    $external = app(ExternalOrderService::class)->save($order->id, $seller->id, [['name' => 'صوص', 'quantity' => 1, 'price' => 40]]);
    $external->collections()->create(['shift_id' => $shift->id, 'user_id' => $user->id, 'method' => 'cash', 'amount' => 40]);
    $order->update(['status' => OrderStatus::COMPLETED]);

    foreach (['print.receipt-template', 'print.receipt-footer'] as $view) {
        $html = view($view, ['order' => $order->fresh(['items', 'payments', 'user'])])->render();
        expect($html)->toContain('طلب خارجي — عربة الصوص')
            ->toContain('إجمالي المطعم: 100.00')
            ->toContain('المطلوب من العميل: 140.00');
    }
    $header = view('print.receipt-header', [
        'order' => $order->fresh(['items', 'payments', 'user']),
        'items' => collect(),
        'receiptView' => \App\Orders\ReceiptView::forOrder($order),
    ])->render();
    expect($header)->not->toContain('طلب خارجي — عربة الصوص')
        ->not->toContain('المطلوب من العميل');
    $stats = app(ShiftsReportService::class)->calculateShiftStats($shift);
    expect((float) $stats['availableCash'])->toBe(50.0);
    app(ExternalSellerSettlementService::class)->pay($seller->id, 40, null);
    $stats = app(ShiftsReportService::class)->calculateShiftStats($shift);
    expect((float) $stats['availableCash'])->toBe(10.0)
        ->and((float) $stats['sales'])->toBe(100.0);
    $period = app(ShiftsReportService::class)->calculatePeriodStats(null, null, [$shift->id]);
    expect((float) $period['externalCash'])->toBe(40.0)
        ->and((float) $period['settlements'])->toBe(40.0)
        ->and((float) $period['availableCash'])->toBe(10.0);
});

it('removes collections and reverses a settled amount when cancelling the host', function () {
    $user = User::factory()->admin()->create();
    $shift = Shift::factory()->active()->create(['user_id' => $user->id]);
    $order = Order::factory()->create(['user_id' => $user->id, 'shift_id' => $shift->id, 'type' => 'takeaway', 'status' => OrderStatus::PROCESSING]);
    $seller = ExternalSeller::create(['name' => 'عربة الصوص', 'is_active' => true]);
    $this->actingAs($user);
    $external = app(ExternalOrderService::class)->save($order->id, $seller->id, [['name' => 'صوص', 'quantity' => 1, 'price' => 40]]);
    $external->collections()->create(['shift_id' => $shift->id, 'user_id' => $user->id, 'method' => 'cash', 'amount' => 40]);
    $settlement = app(ExternalSellerSettlementService::class);
    $settlement->pay($seller->id, 40, null);

    app(OrderService::class)->cancelOrder($order->id);

    expect($external->fresh()->status)->toBe('cancelled')
        ->and($external->collections()->count())->toBe(0)
        ->and($settlement->payable($seller))->toBe(0.0)
        ->and((float) $seller->settlements()->min('amount'))->toBe(-40.0);
});

it('cancels a completed paid order and removes its external collections', function () {
    $user = User::factory()->admin()->create();
    $shift = Shift::factory()->active()->create(['user_id' => $user->id]);
    $product = Product::factory()->create(['price' => 100, 'cost' => 40, 'type' => 'consumable']);
    InventoryItem::query()->updateOrCreate(['product_id' => $product->id], ['quantity' => 10]);
    $order = Order::factory()->create(['user_id' => $user->id, 'shift_id' => $shift->id, 'type' => 'takeaway', 'status' => OrderStatus::PROCESSING, 'discount' => 0, 'temp_discount_percent' => 0]);
    $order->items()->create(['product_id' => $product->id, 'quantity' => 1, 'price' => 100, 'cost' => 40, 'total' => 100]);
    $seller = ExternalSeller::create(['name' => 'عربة الصوص', 'is_active' => true]);
    $this->actingAs($user);
    $external = app(ExternalOrderService::class)->save($order->id, $seller->id, [['name' => 'صوص', 'quantity' => 1, 'price' => 40]]);
    app(InventoryDailyAggregationService::class)->openDay();
    $orders = app(OrderService::class);
    $orders->completeOrder($order->id, ['cash' => 140]);
    app(ExternalSellerSettlementService::class)->pay($seller->id, 40, null);

    $orders->cancelOrder($order->id);

    expect($external->fresh()->status)->toBe('cancelled')
        ->and($external->collections()->count())->toBe(0)
        ->and($order->payments()->count())->toBe(0)
        ->and(app(ExternalSellerSettlementService::class)->payable($seller))->toBe(0.0);
});

it('completes an external-only order without changing stock', function () {
    $user = User::factory()->admin()->create();
    $shift = Shift::factory()->active()->create(['user_id' => $user->id]);
    $product = Product::factory()->create(['type' => 'consumable']);
    $inventory = InventoryItem::query()->firstOrCreate(['product_id' => $product->id], ['quantity' => 25]);
    $inventory->update(['quantity' => 25]);
    $order = Order::factory()->create(['user_id' => $user->id, 'shift_id' => $shift->id, 'type' => 'takeaway', 'status' => OrderStatus::PROCESSING, 'discount' => 0, 'temp_discount_percent' => 0]);
    $seller = ExternalSeller::create(['name' => 'عربة الصوص', 'is_active' => true]);
    $this->actingAs($user);
    app(ExternalOrderService::class)->save($order->id, $seller->id, [['name' => 'صوص', 'quantity' => 1, 'price' => 40]]);

    $completed = app(OrderService::class)->completeOrder($order->id, ['cash' => 40]);

    expect($completed->status)->toBe(OrderStatus::COMPLETED)
        ->and((float) $completed->total)->toBe(0.0)
        ->and((float) $completed->profit)->toBe(0.0)
        ->and((float) $inventory->fresh()->quantity)->toBe(25.0)
        ->and((float) $completed->payments()->sum('amount'))->toBe(0.0);
});

it('saves, fulfills and removes seller lines through the till and includes them in sync', function () {
    $user = User::factory()->admin()->create();
    $shift = Shift::factory()->active()->create(['user_id' => $user->id]);
    $order = Order::factory()->create(['user_id' => $user->id, 'shift_id' => $shift->id, 'type' => 'takeaway', 'status' => OrderStatus::PROCESSING]);
    $seller = ExternalSeller::create(['name' => 'عربة الصوص', 'is_active' => true]);
    $this->actingAs($user);

    $this->post(route('orders.external.save', $order), [
        'seller_id' => $seller->id,
        'items' => [['name' => 'صوص', 'quantity' => 2, 'price' => 20]],
    ])->assertRedirect()->assertSessionHas('success');
    $external = $order->externalOrders()->firstOrFail();
    $this->get(route('orders.manage', $order))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Orders/ManageOrder')
        ->has('externalOrders', 1)
        ->has('sellers', 1)
        ->where('guestDue', round((float) $order->total + 40, 2)));
    $this->post(route('orders.external.fulfill', [$order, $external->id]))->assertRedirect();
    expect($external->fresh()->status)->toBe('fulfilled');
    $document = app(OrderBuilder::class)->build($order->uuid);
    expect($document['external_orders'][0]['seller_name'])->toBe('عربة الصوص')
        ->and($document['external_orders'][0]['items'][0]['name'])->toBe('صوص');
    $this->delete(route('orders.external.removeLine', [$order, $external->id, $external->items()->firstOrFail()->id]))->assertRedirect();
    expect((float) $external->fresh()->total)->toBe(0.0);
});

it('renders seller administration and payable accounts for an administrator', function () {
    $user = User::factory()->admin()->create();
    ExternalSeller::create(['name' => 'عربة الصوص', 'is_active' => true]);
    $this->actingAs($user);

    $this->get(ExternalSellerResource::getUrl('index'))->assertOk()->assertSee('عربة الصوص');
    $this->get(ExternalSellerAccounts::getUrl())->assertOk()->assertSee('عربة الصوص');
});

it('restricts seller management to the seller permissions', function () {
    $user = User::factory()->cashier()->create();
    $this->actingAs($user);

    expect($user->can('viewAny', ExternalSeller::class))->toBeFalse()
        ->and($user->can('create', ExternalSeller::class))->toBeFalse();
    $user->givePermissionTo(['view_external_sellers', 'create_external_sellers']);
    expect($user->fresh()->can('viewAny', ExternalSeller::class))->toBeTrue()
        ->and($user->fresh()->can('create', ExternalSeller::class))->toBeTrue();
});

it('pays a seller from the accounts page', function () {
    $user = User::factory()->admin()->create();
    $shift = Shift::factory()->active()->create(['user_id' => $user->id]);
    $order = Order::factory()->create(['user_id' => $user->id, 'shift_id' => $shift->id]);
    $seller = ExternalSeller::create(['name' => 'عربة الصوص', 'is_active' => true]);
    $external = $seller->orders()->create(['host_order_id' => $order->id, 'seller_name' => $seller->name, 'status' => 'pending', 'total' => 40]);
    $external->collections()->create(['shift_id' => $shift->id, 'user_id' => $user->id, 'method' => 'cash', 'amount' => 40]);
    $this->actingAs($user);

    Livewire::test(ExternalSellerAccounts::class)->callTableAction('pay', $seller, ['amount' => 40]);

    expect(app(ExternalSellerSettlementService::class)->payable($seller))->toBe(0.0);
});
