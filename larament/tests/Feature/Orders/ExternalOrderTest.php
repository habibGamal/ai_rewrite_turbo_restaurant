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

    app(ExternalOrderService::class)->save($order->id, $seller->id, [['product_id' => $seller->products()->create(['name' => 'صوص', 'price' => 40])->id, 'quantity' => 1]]);
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

    expect(fn () => app(ExternalOrderService::class)->save($order->id, $seller->id, [['product_id' => $seller->products()->create(['name' => 'صوص', 'price' => 40])->id, 'quantity' => 1]]))
        ->toThrow(OrderException::class);
});

it('allocates seller shares in order with cash before card', function () {
    $user = User::factory()->admin()->create();
    $shift = Shift::factory()->active()->create(['user_id' => $user->id]);
    $order = Order::factory()->create(['user_id' => $user->id, 'shift_id' => $shift->id, 'type' => 'takeaway', 'status' => OrderStatus::PROCESSING, 'total' => 100]);
    $first = ExternalSeller::create(['name' => 'البائع الأول', 'is_active' => true]);
    $second = ExternalSeller::create(['name' => 'البائع الثاني', 'is_active' => true]);
    $service = app(ExternalOrderService::class);
    $firstOrder = $service->save($order->id, $first->id, [['product_id' => $first->products()->create(['name' => 'صوص', 'price' => 40])->id, 'quantity' => 1]]);
    $secondOrder = $service->save($order->id, $second->id, [['product_id' => $second->products()->create(['name' => 'مشروب', 'price' => 30])->id, 'quantity' => 1]]);

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
    $external = app(ExternalOrderService::class)->save($order->id, $seller->id, [['product_id' => $seller->products()->create(['name' => 'صوص', 'price' => 40])->id, 'quantity' => 1]]);

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
    $external = app(ExternalOrderService::class)->save($order->id, $seller->id, [['product_id' => $seller->products()->create(['name' => 'صوص', 'price' => 40])->id, 'quantity' => 1]]);

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
    $external = app(ExternalOrderService::class)->save($order->id, $seller->id, [['product_id' => $seller->products()->create(['name' => 'صوص', 'price' => 40])->id, 'quantity' => 1]]);
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
    $external = app(ExternalOrderService::class)->save($order->id, $seller->id, [['product_id' => $seller->products()->create(['name' => 'صوص', 'price' => 40])->id, 'quantity' => 1]]);
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
    $external = app(ExternalOrderService::class)->save($order->id, $seller->id, [['product_id' => $seller->products()->create(['name' => 'صوص', 'price' => 40])->id, 'quantity' => 1]]);
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
    app(ExternalOrderService::class)->save($order->id, $seller->id, [['product_id' => $seller->products()->create(['name' => 'صوص', 'price' => 40])->id, 'quantity' => 1]]);

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
        'items' => [['product_id' => $seller->products()->create(['name' => 'صوص', 'price' => 20])->id, 'quantity' => 2]],
    ])->assertRedirect()->assertSessionHas('success');
    $external = $order->externalOrders()->firstOrFail();
    $this->get(route('orders.manage', $order))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Orders/ManageOrder')
        ->has('externalOrders', 1)
        ->has('sellers', 1)
        ->where('canAddExternalOrder', true)
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

it('does not offer external orders on a partial-payment type', function () {
    $user = User::factory()->admin()->create();
    $shift = Shift::factory()->active()->create(['user_id' => $user->id]);
    $order = Order::factory()->create(['user_id' => $user->id, 'shift_id' => $shift->id, 'type' => 'companies', 'status' => OrderStatus::PROCESSING]);
    $this->actingAs($user);

    $this->get(route('orders.manage', $order))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Orders/ManageOrder')
        ->where('canAddExternalOrder', false));
});

it('stores the product price from the server', function () {
    $user = User::factory()->admin()->create();
    $shift = Shift::factory()->active()->create(['user_id' => $user->id]);
    $order = Order::factory()->create(['user_id' => $user->id, 'shift_id' => $shift->id, 'type' => 'takeaway', 'status' => OrderStatus::PROCESSING]);
    $seller = ExternalSeller::create(['name' => 'Seller', 'is_active' => true]);
    $product = $seller->products()->create(['name' => 'Sauce', 'price' => 20, 'is_active' => true]);
    $this->actingAs($user)->post(route('orders.external.save', $order), [
        'seller_id' => $seller->id,
        'items' => [['product_id' => $product->id, 'quantity' => 2, 'price' => 1]],
    ])->assertSessionHasNoErrors();
    $item = $order->externalOrders()->firstOrFail()->items()->firstOrFail();
    expect((float) $item->price)->toBe(20.0)->and((float) $item->total)->toBe(40.0);
    $product->update(['name' => 'Changed', 'price' => 99]);
    expect($item->fresh()->name)->toBe('Sauce')->and((float) $item->fresh()->price)->toBe(20.0);
});

it('lists active sellers and their active products in name order', function () {
    $user = User::factory()->admin()->create();
    $shift = Shift::factory()->active()->create(['user_id' => $user->id]);
    $order = Order::factory()->create(['user_id' => $user->id, 'shift_id' => $shift->id, 'type' => 'takeaway', 'status' => OrderStatus::PROCESSING]);
    $seller = ExternalSeller::create(['name' => 'Active', 'is_active' => true]);
    $seller->products()->create(['name' => 'Z', 'price' => 10]);
    $first = $seller->products()->create(['name' => 'A', 'price' => 20]);
    $seller->products()->create(['name' => 'Hidden', 'price' => 5, 'is_active' => false]);
    ExternalSeller::create(['name' => 'Inactive', 'is_active' => false]);

    $this->actingAs($user)->get(route('orders.manage', $order))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('sellers', 1)->has('sellers.0.products', 2)
        ->where('sellers.0.products.0.id', $first->id)
        ->where('sellers.0.products.1.name', 'Z'));
});

it('rejects inactive products and products belonging to another seller', function () {
    $user = User::factory()->admin()->create();
    $shift = Shift::factory()->active()->create(['user_id' => $user->id]);
    $order = Order::factory()->create(['user_id' => $user->id, 'shift_id' => $shift->id, 'type' => 'takeaway', 'status' => OrderStatus::PROCESSING]);
    $seller = ExternalSeller::create(['name' => 'Seller', 'is_active' => true]);
    $other = ExternalSeller::create(['name' => 'Other', 'is_active' => true]);
    $inactive = $seller->products()->create(['name' => 'Inactive', 'price' => 10, 'is_active' => false]);
    $foreign = $other->products()->create(['name' => 'Foreign', 'price' => 10]);
    foreach ([$inactive, $foreign] as $product) {
        $this->actingAs($user)->post(route('orders.external.save', $order), [
            'seller_id' => $seller->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('items.0.product_id');
    }
    expect($order->externalOrders()->count())->toBe(0);
});

it('creates seller products through the relation manager', function () {
    $user = User::factory()->admin()->create();
    $seller = ExternalSeller::create(['name' => 'Seller', 'is_active' => true]);
    $this->actingAs($user);
    Livewire::test(\App\Filament\Resources\ExternalSellers\RelationManagers\ProductsRelationManager::class, [
        'ownerRecord' => $seller,
        'pageClass' => \App\Filament\Resources\ExternalSellers\Pages\EditExternalSeller::class,
    ])->callTableAction('create', data: ['name' => 'Sauce', 'price' => 12.5, 'is_active' => true])
        ->assertHasNoTableActionErrors();
    expect($seller->products()->where('name', 'Sauce')->exists())->toBeTrue();
});

it('removes legacy lines and replaces them when saving selected products', function () {
    $user = User::factory()->admin()->create();
    $shift = Shift::factory()->active()->create(['user_id' => $user->id]);
    $order = Order::factory()->create(['user_id' => $user->id, 'shift_id' => $shift->id, 'type' => 'takeaway', 'status' => OrderStatus::PROCESSING]);
    $seller = ExternalSeller::create(['name' => 'Seller', 'is_active' => true]);
    $product = $seller->products()->create(['name' => 'Sauce', 'price' => 20]);
    $external = $seller->orders()->create(['host_order_id' => $order->id, 'seller_name' => $seller->name, 'status' => 'pending', 'total' => 30]);
    $legacy = $external->items()->create(['name' => 'Legacy', 'quantity' => 1, 'price' => 30, 'total' => 30]);
    $this->actingAs($user)->delete(route('orders.external.removeLine', [$order, $external->id, $legacy->id]))->assertSessionHasNoErrors();
    expect($external->items()->count())->toBe(0);
    $external->items()->create(['name' => 'Legacy', 'quantity' => 1, 'price' => 30, 'total' => 30]);
    $this->post(route('orders.external.save', $order), [
        'seller_id' => $seller->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertSessionHasNoErrors();
    expect($external->items()->count())->toBe(1)
        ->and($external->items()->first()->external_product_id)->toBe($product->id);
    $product->delete();
    expect($external->items()->first()->external_product_id)->toBeNull()
        ->and($external->items()->first()->name)->toBe('Sauce');
});
