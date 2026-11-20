<?php

use App\Filament\Exports\CashierCommissionTableExporter;
use App\Filament\Exports\CashierItemChangesTableExporter;
use App\Filament\Exports\CashierOrdersTableExporter;
use App\Filament\Pages\Reports\CashierCommissionReport;
use App\Filament\Pages\Reports\CashierPerformanceDetails;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\OrderTimelineRelationManager;
use App\Filament\Widgets\CashierCommissionStatsWidget;
use App\Filament\Widgets\CashierCommissionTableWidget;
use App\Filament\Widgets\CashierDetailStatsWidget;
use App\Filament\Widgets\CashierItemChangesTableWidget;
use App\Filament\Widgets\CashierOrdersTableWidget;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\OrderItem;
use App\Models\OrderItemChange;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Services\CashierCommissionReportService;
use App\Services\OrderHistoryService;
use App\Services\OrderItemChangeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user1 = User::factory()->create(['name' => 'Cashier One']);
    $this->user2 = User::factory()->create(['name' => 'Cashier Two']);

    $this->shift = Shift::factory()->create([
        'user_id' => $this->user1->id,
        'start_at' => now()->subDay(),
        'closed' => false,
    ]);

    $this->category = Category::factory()->create(['name' => 'Food']);
    $this->product1 = Product::factory()->create([
        'name' => 'Burger',
        'category_id' => $this->category->id,
        'price' => 100.00,
        'cost' => 50.00,
    ]);
    $this->product2 = Product::factory()->create([
        'name' => 'Fries',
        'category_id' => $this->category->id,
        'price' => 40.00,
        'cost' => 20.00,
    ]);
});

test('records user_id, unit_price, and total_price on order item changes', function () {
    $order = Order::factory()->create([
        'user_id' => $this->user1->id,
        'shift_id' => $this->shift->id,
        'total' => 200,
    ]);

    $this->actingAs($this->user1);

    $differences = [
        [
            'type' => 'added',
            'product_id' => $this->product1->id,
            'product_name' => $this->product1->name,
            'quantity' => 2,
            'unit_price' => 100.00,
            'description' => 'تم إضافة 2 من Burger',
        ],
    ];

    app(OrderItemChangeService::class)->recordChanges($order->id, $differences);

    $change = OrderItemChange::first();
    expect($change)->not->toBeNull()
        ->and($change->user_id)->toBe($this->user1->id)
        ->and((float) $change->unit_price)->toBe(100.00)
        ->and((float) $change->total_price)->toBe(200.00)
        ->and((float) $change->delta)->toBe(2.000);
});

test('ordered_by_cashiers returns single cashier name when only one cashier ordered it', function () {
    $order = Order::factory()->create([
        'user_id' => $this->user1->id,
        'shift_id' => $this->shift->id,
    ]);

    $item = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $this->product1->id,
        'quantity' => 2,
        'price' => 100,
    ]);

    OrderItemChange::create([
        'order_id' => $order->id,
        'user_id' => $this->user1->id,
        'product_id' => $this->product1->id,
        'product_name' => 'Burger',
        'change_type' => 'added',
        'new_quantity' => 2,
        'delta' => 2,
        'unit_price' => 100,
        'total_price' => 200,
    ]);

    expect($item->ordered_by_cashiers)->toBe('Cashier One');
});

test('ordered_by_cashiers lists all cashiers with quantities when multiple cashiers ordered it', function () {
    $order = Order::factory()->create([
        'user_id' => $this->user1->id,
        'shift_id' => $this->shift->id,
    ]);

    $item = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $this->product1->id,
        'quantity' => 3,
        'price' => 100,
    ]);

    // Cashier One added 2
    OrderItemChange::create([
        'order_id' => $order->id,
        'user_id' => $this->user1->id,
        'product_id' => $this->product1->id,
        'product_name' => 'Burger',
        'change_type' => 'added',
        'new_quantity' => 2,
        'delta' => 2,
        'unit_price' => 100,
        'total_price' => 200,
    ]);

    // Cashier Two added 1
    OrderItemChange::create([
        'order_id' => $order->id,
        'user_id' => $this->user2->id,
        'product_id' => $this->product1->id,
        'product_name' => 'Burger',
        'change_type' => 'quantity_changed',
        'old_quantity' => 2,
        'new_quantity' => 3,
        'delta' => 1,
        'unit_price' => 100,
        'total_price' => 100,
    ]);

    expect($item->ordered_by_cashiers)->toBe('Cashier One (2)، Cashier Two (1)');
});

test('ordered_by_cashiers falls back to order user when no changes exist', function () {
    $order = Order::factory()->create([
        'user_id' => $this->user1->id,
        'shift_id' => $this->shift->id,
    ]);

    $item = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $this->product1->id,
        'quantity' => 1,
    ]);

    expect($item->ordered_by_cashiers)->toBe('Cashier One');
});

test('OrderHistoryService records actions and can sync history for existing orders', function () {
    $order = Order::factory()->create([
        'user_id' => $this->user1->id,
        'shift_id' => $this->shift->id,
        'order_number' => 105,
        'status' => 'completed',
    ]);

    $service = app(OrderHistoryService::class);
    $service->syncOrderHistory($order);

    $histories = OrderHistory::where('order_id', $order->id)->get();
    expect($histories->count())->toBeGreaterThanOrEqual(2);

    $createdHistory = $histories->where('action', 'created')->first();
    expect($createdHistory)->not->toBeNull()
        ->and($createdHistory->user_id)->toBe($this->user1->id)
        ->and($createdHistory->description)->toContain('105');

    $completedHistory = $histories->where('action', 'completed')->first();
    expect($completedHistory)->not->toBeNull()
        ->and($completedHistory->user_id)->toBe($this->user1->id);
});

test('CashierCommissionReportService computes accurate orders and item changes metrics', function () {
    // User 1 creates an order of 300 EGP
    $order1 = Order::factory()->create([
        'user_id' => $this->user1->id,
        'shift_id' => $this->shift->id,
        'total' => 300,
    ]);

    // User 2 creates an order of 500 EGP
    $order2 = Order::factory()->create([
        'user_id' => $this->user2->id,
        'shift_id' => $this->shift->id,
        'total' => 500,
    ]);

    // User 1 adds 2 burgers (200 EGP) to order 1
    OrderItemChange::create([
        'order_id' => $order1->id,
        'user_id' => $this->user1->id,
        'product_id' => $this->product1->id,
        'product_name' => 'Burger',
        'change_type' => 'added',
        'new_quantity' => 2,
        'delta' => 2,
        'unit_price' => 100,
        'total_price' => 200,
    ]);

    // User 1 removes 1 fries (40 EGP) from order 1
    OrderItemChange::create([
        'order_id' => $order1->id,
        'user_id' => $this->user1->id,
        'product_id' => $this->product2->id,
        'product_name' => 'Fries',
        'change_type' => 'removed',
        'old_quantity' => 1,
        'new_quantity' => 0,
        'delta' => -1,
        'unit_price' => 40,
        'total_price' => 40,
    ]);

    // User 2 adds 3 burgers (300 EGP) to order 2
    OrderItemChange::create([
        'order_id' => $order2->id,
        'user_id' => $this->user2->id,
        'product_id' => $this->product1->id,
        'product_name' => 'Burger',
        'change_type' => 'added',
        'new_quantity' => 3,
        'delta' => 3,
        'unit_price' => 100,
        'total_price' => 300,
    ]);

    $service = app(CashierCommissionReportService::class);
    $results = $service->getCashierCommissionQuery([$this->shift->id])->get()->keyBy('id');

    expect($results->has($this->user1->id))->toBeTrue()
        ->and($results->has($this->user2->id))->toBeTrue();

    $u1Row = $results->get($this->user1->id);
    expect((int) $u1Row->orders_count)->toBe(1)
        ->and((float) $u1Row->orders_total_value)->toBe(300.0)
        ->and((float) $u1Row->items_added_count)->toBe(2.0)
        ->and((float) $u1Row->items_added_value)->toBe(200.0)
        ->and((float) $u1Row->items_removed_count)->toBe(1.0)
        ->and((float) $u1Row->items_removed_value)->toBe(40.0)
        ->and((float) $u1Row->net_items_value)->toBe(160.0);

    $u2Row = $results->get($this->user2->id);
    expect((int) $u2Row->orders_count)->toBe(1)
        ->and((float) $u2Row->orders_total_value)->toBe(500.0)
        ->and((float) $u2Row->items_added_count)->toBe(3.0)
        ->and((float) $u2Row->items_added_value)->toBe(300.0)
        ->and((float) $u2Row->items_removed_count)->toBe(0.0)
        ->and((float) $u2Row->items_removed_value)->toBe(0.0)
        ->and((float) $u2Row->net_items_value)->toBe(300.0);

    $stats = $service->getSummaryStats([$this->shift->id]);
    expect($stats['total_orders'])->toBe(2)
        ->and($stats['total_orders_value'])->toBe(800.0)
        ->and($stats['total_items_added'])->toBe(5.0)
        ->and($stats['total_items_added_value'])->toBe(500.0)
        ->and($stats['total_items_removed'])->toBe(1.0)
        ->and($stats['total_items_removed_value'])->toBe(40.0);
});

test('CashierCommissionReportService accurately calculates orders with and without discounts and getCashierStats', function () {
    // User 1 has:
    // Order 1: total 200, discount 50 (with discount)
    $order1 = Order::factory()->create([
        'user_id' => $this->user1->id,
        'shift_id' => $this->shift->id,
        'total' => 200,
        'discount' => 50,
    ]);

    // Order 2: total 300, discount 0 (without discount)
    $order2 = Order::factory()->create([
        'user_id' => $this->user1->id,
        'shift_id' => $this->shift->id,
        'total' => 300,
        'discount' => 0,
    ]);

    // Order 3: total 150, discount 0 (without discount)
    $order3 = Order::factory()->create([
        'user_id' => $this->user1->id,
        'shift_id' => $this->shift->id,
        'total' => 150,
        'discount' => 0,
    ]);

    // User 1 item changes:
    // added 2 Burger (200 EGP)
    OrderItemChange::create([
        'order_id' => $order1->id,
        'user_id' => $this->user1->id,
        'product_id' => $this->product1->id,
        'product_name' => 'Burger',
        'change_type' => 'added',
        'new_quantity' => 2,
        'delta' => 2,
        'unit_price' => 100,
        'total_price' => 200,
    ]);

    // removed 1 Fries (40 EGP)
    OrderItemChange::create([
        'order_id' => $order1->id,
        'user_id' => $this->user1->id,
        'product_id' => $this->product2->id,
        'product_name' => 'Fries',
        'change_type' => 'removed',
        'old_quantity' => 1,
        'new_quantity' => 0,
        'delta' => -1,
        'unit_price' => 40,
        'total_price' => 40,
    ]);

    $service = app(CashierCommissionReportService::class);
    $query = $service->getCashierCommissionQuery([$this->shift->id])->get()->keyBy('id');
    $userStats = $query->get($this->user1->id);

    expect((int) $userStats->orders_count)->toBe(3)
        ->and((float) $userStats->orders_total_value)->toBe(650.0)
        ->and((int) $userStats->orders_with_discount_count)->toBe(1)
        ->and((float) $userStats->orders_with_discount_value)->toBe(200.0)
        ->and((float) $userStats->total_discount_amount)->toBe(50.0)
        ->and((int) $userStats->orders_without_discount_count)->toBe(2)
        ->and((float) $userStats->orders_without_discount_value)->toBe(450.0)
        ->and((float) $userStats->items_added_count)->toBe(2.0)
        ->and((float) $userStats->items_added_value)->toBe(200.0)
        ->and((float) $userStats->items_removed_count)->toBe(1.0)
        ->and((float) $userStats->items_removed_value)->toBe(40.0)
        ->and((float) $userStats->net_items_value)->toBe(160.0);

    // Test getCashierStats
    $individualStats = $service->getCashierStats($this->user1->id, [$this->shift->id]);
    expect($individualStats['orders_count'])->toBe(3)
        ->and($individualStats['orders_total_value'])->toBe(650.0)
        ->and($individualStats['orders_with_discount_count'])->toBe(1)
        ->and($individualStats['orders_with_discount_value'])->toBe(200.0)
        ->and($individualStats['total_discount_amount'])->toBe(50.0)
        ->and($individualStats['orders_without_discount_count'])->toBe(2)
        ->and($individualStats['orders_without_discount_value'])->toBe(450.0)
        ->and($individualStats['items_added_count'])->toBe(2.0)
        ->and($individualStats['items_added_value'])->toBe(200.0)
        ->and($individualStats['items_removed_count'])->toBe(1.0)
        ->and($individualStats['items_removed_value'])->toBe(40.0)
        ->and($individualStats['net_items_value'])->toBe(160.0);
});

test('CashierCommissionReport page and table widget render successfully', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Create an order for user1 in this shift
    Order::factory()->create([
        'user_id' => $this->user1->id,
        'shift_id' => $this->shift->id,
        'total' => 250,
        'discount' => 10,
    ]);

    livewire(CashierCommissionReport::class)
        ->assertOk();

    livewire(CashierCommissionStatsWidget::class, [
        'pageFilters' => [
            'filterType' => 'shifts',
            'shifts' => [$this->shift->id],
        ],
    ])->assertOk();

    livewire(CashierCommissionTableWidget::class, [
        'pageFilters' => [
            'filterType' => 'shifts',
            'shifts' => [$this->shift->id],
        ],
    ])->assertOk()
        ->assertCanSeeTableRecords([$this->user1])
        ->assertTableActionExists('export')
        ->assertTableActionExists('view_details')
        ->assertTableActionDoesNotExist('view_changes')
        ->assertTableActionDoesNotExist('view_orders');
});

test('CashierCommissionTableExporter correctly formats and exports cashier performance metrics', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Create an order with discount
    $order = Order::factory()->create([
        'user_id' => $this->user1->id,
        'shift_id' => $this->shift->id,
        'total' => 200,
        'discount' => 25,
    ]);

    // Item addition
    OrderItemChange::create([
        'order_id' => $order->id,
        'user_id' => $this->user1->id,
        'product_id' => $this->product1->id,
        'product_name' => 'Burger',
        'change_type' => 'added',
        'new_quantity' => 2,
        'delta' => 2,
        'unit_price' => 100,
        'total_price' => 200,
    ]);

    $service = app(CashierCommissionReportService::class);
    $cashierRecord = $service->getCashierCommissionQuery([$this->shift->id])->where('users.id', $this->user1->id)->first();

    expect($cashierRecord)->not->toBeNull();

    $columns = collect(CashierCommissionTableExporter::getColumns())
        ->mapWithKeys(fn ($column) => [$column->getName() => $column->getLabel()])
        ->all();

    expect($columns)->toHaveKeys([
        'name',
        'orders_count',
        'orders_total_value',
        'orders_without_discount_count',
        'orders_without_discount_value',
        'orders_with_discount_count',
        'orders_with_discount_value',
        'total_discount_amount',
        'items_added_count',
        'items_added_value',
        'items_removed_count',
        'items_removed_value',
        'net_items_value',
    ]);

    $export = new Export(['exporter' => CashierCommissionTableExporter::class]);
    $export->user()->associate($this->user1);
    $exporter = new CashierCommissionTableExporter($export, $columns, []);

    $exportedRow = $exporter($cashierRecord);

    expect($exportedRow)->toBe([
        $this->user1->name,
        '1',        // orders_count
        '200.00',   // orders_total_value
        '0',        // orders_without_discount_count
        '0.00',     // orders_without_discount_value
        '1',        // orders_with_discount_count
        '200.00',   // orders_with_discount_value
        '25.00',    // total_discount_amount
        '2',        // items_added_count
        '200.00',   // items_added_value
        '0',        // items_removed_count
        '0.00',     // items_removed_value
        '200.00',   // net_items_value
    ]);

    $notification = CashierCommissionTableExporter::getCompletedNotificationBody($export);
    expect($notification)->toContain('تم إكمال تصدير تقرير عمولات وأداء الكاشير');
});

test('CashierPerformanceDetails page and widgets render successfully', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $order = Order::factory()->create([
        'user_id' => $this->user1->id,
        'shift_id' => $this->shift->id,
        'total' => 300,
        'discount' => 0,
    ]);

    OrderItemChange::create([
        'order_id' => $order->id,
        'user_id' => $this->user1->id,
        'product_id' => $this->product1->id,
        'product_name' => 'Burger',
        'change_type' => 'added',
        'new_quantity' => 2,
        'delta' => 2,
        'unit_price' => 100,
        'total_price' => 200,
    ]);

    // Test CashierPerformanceDetails custom report page
    livewire(CashierPerformanceDetails::class, ['record' => $this->user1->id])
        ->assertOk()
        ->assertSee($this->user1->name)
        ->assertSee('الطلبات المنشأة')
        ->assertSee('سجل تعديلات الأصناف');

    // Test CashierDetailStatsWidget
    livewire(CashierDetailStatsWidget::class, ['record' => $this->user1])
        ->assertOk();

    // Test CashierOrdersTableWidget
    livewire(CashierOrdersTableWidget::class, ['userId' => $this->user1->id])
        ->assertOk()
        ->assertCanSeeTableRecords([$order])
        ->assertTableActionExists('export');

    // Test CashierItemChangesTableWidget
    livewire(CashierItemChangesTableWidget::class, ['userId' => $this->user1->id])
        ->assertOk()
        ->assertSee('Burger')
        ->assertTableActionExists('export');
});

test('CashierOrdersTableExporter and CashierItemChangesTableExporter correctly export data', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $order = Order::factory()->create([
        'user_id' => $this->user1->id,
        'shift_id' => $this->shift->id,
        'order_number' => 888,
        'total' => 250,
        'discount' => 15,
        'sub_total' => 265,
    ]);

    $change = OrderItemChange::create([
        'order_id' => $order->id,
        'user_id' => $this->user1->id,
        'product_id' => $this->product1->id,
        'product_name' => 'Burger',
        'change_type' => 'added',
        'new_quantity' => 2,
        'delta' => 2,
        'unit_price' => 100,
        'total_price' => 200,
    ]);

    // Test CashierOrdersTableExporter
    $orderColumns = collect(CashierOrdersTableExporter::getColumns())
        ->mapWithKeys(fn ($column) => [$column->getName() => $column->getLabel()])
        ->all();

    expect($orderColumns)->toHaveKeys([
        'order_number',
        'type',
        'status',
        'payment_status',
        'sub_total',
        'discount',
        'total',
        'dine_table_number',
        'customer_name',
        'created_at',
    ]);

    $exportOrders = new Export(['exporter' => CashierOrdersTableExporter::class]);
    $exportOrders->user()->associate($this->user1);
    $ordersExporter = new CashierOrdersTableExporter($exportOrders, $orderColumns, []);
    $orderExportedRow = $ordersExporter($order);

    expect($orderExportedRow[0])->toBe('888');
    expect($orderExportedRow[4])->toBe('265.00'); // sub_total
    expect($orderExportedRow[5])->toBe('15.00');  // discount
    expect($orderExportedRow[6])->toBe('250.00'); // total

    // Test CashierItemChangesTableExporter
    $changeColumns = collect(CashierItemChangesTableExporter::getColumns())
        ->mapWithKeys(fn ($column) => [$column->getName() => $column->getLabel()])
        ->all();

    expect($changeColumns)->toHaveKeys([
        'order_number',
        'product_name',
        'change_type',
        'old_quantity',
        'new_quantity',
        'delta',
        'unit_price',
        'total_price',
        'created_at',
    ]);

    $exportChanges = new Export(['exporter' => CashierItemChangesTableExporter::class]);
    $exportChanges->user()->associate($this->user1);
    $changesExporter = new CashierItemChangesTableExporter($exportChanges, $changeColumns, []);
    $changeExportedRow = $changesExporter($change);

    expect($changeExportedRow[0])->toBe('#888');
    expect($changeExportedRow[1])->toBe('Burger');
    expect($changeExportedRow[2])->toBe('إضافة');
    expect($changeExportedRow[5])->toBe('+2');
    expect($changeExportedRow[6])->toBe('100.00');
    expect($changeExportedRow[7])->toBe('+200.00');
});

test('CashierPerformanceDetails obeys filters from cashier commission report and matches numbers', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Shift 1 (included)
    $shift1 = $this->shift;
    $order1 = Order::factory()->create([
        'user_id' => $this->user1->id,
        'shift_id' => $shift1->id,
        'order_number' => 101,
        'total' => 200,
        'discount' => 20,
    ]);
    OrderItemChange::create([
        'order_id' => $order1->id,
        'user_id' => $this->user1->id,
        'product_id' => $this->product1->id,
        'product_name' => 'Burger',
        'change_type' => 'added',
        'new_quantity' => 2,
        'delta' => 2,
        'unit_price' => 100,
        'total_price' => 200,
    ]);

    // Shift 2 (excluded)
    $shift2 = Shift::factory()->create([
        'user_id' => $this->user1->id,
        'start_at' => now()->subDays(20),
        'closed' => true,
    ]);
    $order2 = Order::factory()->create([
        'user_id' => $this->user1->id,
        'shift_id' => $shift2->id,
        'order_number' => 102,
        'total' => 500,
        'discount' => 0,
    ]);
    OrderItemChange::create([
        'order_id' => $order2->id,
        'user_id' => $this->user1->id,
        'product_id' => $this->product2->id,
        'product_name' => 'Fries',
        'change_type' => 'added',
        'new_quantity' => 5,
        'delta' => 5,
        'unit_price' => 40,
        'total_price' => 200,
    ]);

    // Test CashierPerformanceDetails with filterType => 'shifts', shifts => [$shift1->id]
    $component = livewire(CashierPerformanceDetails::class, [
        'record' => $this->user1->id,
        'filterType' => 'shifts',
        'shifts' => [$shift1->id],
    ]);

    $component->assertOk()
        ->assertSee($this->user1->name);

    $filter = $component->instance()->getResolvedFilter();
    expect($filter['shiftIds'])->toBe([$shift1->id]);

    // Stats widget should only show order1 numbers (total 200, discount 20)
    livewire(CashierDetailStatsWidget::class, [
        'record' => $this->user1,
        'shiftIds' => [$shift1->id],
        'hasFilter' => true,
    ])
        ->assertOk()
        ->assertSee('1 طلب')
        ->assertSee('200.00 ج.م')
        ->assertSee('20.00 ج.م');

    // Orders table should see order1 and NOT order2
    livewire(CashierOrdersTableWidget::class, [
        'userId' => $this->user1->id,
        'shiftIds' => [$shift1->id],
        'hasFilter' => true,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$order1])
        ->assertCanNotSeeTableRecords([$order2]);

    // Item changes table should see Burger and NOT Fries
    livewire(CashierItemChangesTableWidget::class, [
        'userId' => $this->user1->id,
        'shiftIds' => [$shift1->id],
        'hasFilter' => true,
    ])
        ->assertOk()
        ->assertSee('Burger')
        ->assertDontSee('Fries');
});

test('OrderTimelineRelationManager displays order history timeline', function () {
    $order = Order::factory()->create([
        'user_id' => $this->user1->id,
        'shift_id' => $this->shift->id,
        'order_number' => 202,
        'status' => 'completed',
    ]);

    // Create a history entry
    OrderHistory::create([
        'order_id' => $order->id,
        'user_id' => $this->user1->id,
        'action' => 'created',
        'action_name' => 'إنشاء الطلب',
        'description' => 'تم إنشاء الطلب رقم #202',
    ]);

    livewire(OrderTimelineRelationManager::class, [
        'ownerRecord' => $order,
        'pageClass' => ViewOrder::class,
    ])
        ->assertOk()
        ->assertSee('إنشاء الطلب')
        ->assertSee('تم إنشاء الطلب رقم #202');
});
