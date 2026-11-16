<?php

use App\Enums\InventoryMovementOperation;
use App\Enums\MovementReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductType;
use App\Filament\Resources\StockIssues\Pages\CreateStockIssue;
use App\Filament\Resources\StockIssues\Pages\EditStockIssue;
use App\Filament\Resources\StockIssues\Pages\ListStockIssues;
use App\Filament\Resources\StockIssues\Pages\ViewStockIssue;
use App\Models\InventoryItem;
use App\Models\InventoryItemMovement;
use App\Models\InventoryItemMovementDaily;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\StockIssue;
use App\Models\User;
use App\Models\Warehouse;
use App\Policies\StockIssuePolicy;
use App\Services\ChannelPerformanceReportService;
use App\Services\InventoryDailyAggregationService;
use App\Services\ProductsSalesReportService;
use App\Services\Reports\Channels\ChannelReportFilters;
use App\Services\ShiftsReportService;
use App\Services\StockIssueService;
use App\Services\StockService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

it('issues 3 kg rice at cost 25 → 75, no margin', function () {
    $user = User::factory()->admin()->create();
    actingAs($user);

    $warehouse = Warehouse::default();
    $rice = Product::factory()->create([
        'name' => 'أرز',
        'type' => ProductType::RawMaterial,
        'cost' => 25,
        'price' => 40,
    ]);

    InventoryItem::updateOrCreate(
        ['product_id' => $rice->id, 'warehouse_id' => $warehouse->id],
        ['quantity' => 10]
    );
    openInventoryDay($rice);

    $issue = StockIssue::create([
        'warehouse_id' => $warehouse->id,
        'destination' => 'وجبات الموظفين',
        'notes' => 'صرف غداء العاملين',
        'user_id' => $user->id,
    ]);

    $item = $issue->items()->create([
        'product_id' => $rice->id,
        'quantity' => 3,
    ]);

    app(StockIssueService::class)->confirm($issue);

    $issue->refresh();
    $item->refresh();

    expect((float) $item->unit_cost)->toBe(25.0)
        ->and((float) $item->total_cost)->toBe(75.0)
        ->and((float) $issue->total_cost)->toBe(75.0)
        ->and($issue->closed_at)->not->toBeNull()
        ->and($issue->closed_by)->toBe($user->id)
        ->and($issue->business_date?->toDateString())->toBe(Carbon::today()->toDateString())
        ->and(app(StockService::class)->getCurrentStock($rice->id, $warehouse->id))->toBe(7.0);
});

it('stores the cost at confirm time, even if the cost changed since the draft', function () {
    $user = User::factory()->admin()->create();
    actingAs($user);

    $warehouse = Warehouse::default();
    $rice = Product::factory()->create([
        'name' => 'أرز بسمتي',
        'type' => ProductType::RawMaterial,
        'cost' => 20,
    ]);

    InventoryItem::updateOrCreate(
        ['product_id' => $rice->id, 'warehouse_id' => $warehouse->id],
        ['quantity' => 10]
    );
    openInventoryDay($rice);

    $issue = StockIssue::create([
        'warehouse_id' => $warehouse->id,
        'destination' => 'ضيافة',
        'user_id' => $user->id,
    ]);

    $item = $issue->items()->create([
        'product_id' => $rice->id,
        'quantity' => 3,
        'unit_cost' => 20,
        'total_cost' => 60,
    ]);

    // Cost changes after the draft was made
    $rice->update(['cost' => 25]);

    app(StockIssueService::class)->confirm($issue);

    $issue->refresh();
    $item->refresh();

    expect((float) $item->unit_cost)->toBe(25.0)
        ->and((float) $item->total_cost)->toBe(75.0)
        ->and((float) $issue->total_cost)->toBe(75.0);
});

it('accepts only raw materials and consumables', function () {
    $user = User::factory()->admin()->create();
    actingAs($user);

    $warehouse = Warehouse::default();
    $raw = Product::factory()->create(['type' => ProductType::RawMaterial, 'cost' => 15]);
    $consumable = Product::factory()->create(['type' => ProductType::Consumable, 'cost' => 5]);
    $manufactured = Product::factory()->create(['type' => ProductType::Manufactured, 'cost' => 50]);

    openInventoryDay($raw);

    // Empty issue is rejected
    $emptyIssue = StockIssue::create([
        'warehouse_id' => $warehouse->id,
        'destination' => 'وجبات الموظفين',
        'user_id' => $user->id,
    ]);

    expect(fn () => app(StockIssueService::class)->confirm($emptyIssue))
        ->toThrow(ValidationException::class);

    // Manufactured product is rejected
    $badIssue = StockIssue::create([
        'warehouse_id' => $warehouse->id,
        'destination' => 'وجبات الموظفين',
        'user_id' => $user->id,
    ]);
    $badIssue->items()->create([
        'product_id' => $manufactured->id,
        'quantity' => 2,
    ]);

    expect(fn () => app(StockIssueService::class)->confirm($badIssue))
        ->toThrow(ValidationException::class);

    // Raw material and consumable succeed
    $goodIssue = StockIssue::create([
        'warehouse_id' => $warehouse->id,
        'destination' => 'وجبات الموظفين',
        'user_id' => $user->id,
    ]);
    $goodIssue->items()->create(['product_id' => $raw->id, 'quantity' => 2]);
    $goodIssue->items()->create(['product_id' => $consumable->id, 'quantity' => 4]);

    app(StockIssueService::class)->confirm($goodIssue);

    expect($goodIssue->fresh()->closed_at)->not->toBeNull()
        ->and((float) $goodIssue->fresh()->total_cost)->toBe(50.0);
});

it('records destination, user and warehouse on the movement and aggregates issue_quantity', function () {
    $user = User::factory()->admin()->create();
    actingAs($user);

    $prepWarehouse = Warehouse::factory()->create(['name' => 'مخزن التجهيزات']);
    $oil = Product::factory()->create([
        'name' => 'زيت',
        'type' => ProductType::RawMaterial,
        'cost' => 40,
    ]);

    InventoryItem::updateOrCreate(
        ['product_id' => $oil->id, 'warehouse_id' => $prepWarehouse->id],
        ['quantity' => 20]
    );

    app(InventoryDailyAggregationService::class)->openDay();

    $issue = StockIssue::create([
        'warehouse_id' => $prepWarehouse->id,
        'destination' => 'حفلة أحمد',
        'user_id' => $user->id,
    ]);
    $issue->items()->create([
        'product_id' => $oil->id,
        'quantity' => 2.5,
    ]);

    app(StockIssueService::class)->confirm($issue);

    $movement = InventoryItemMovement::query()
        ->where('product_id', $oil->id)
        ->where('reason', MovementReason::STOCK_ISSUE)
        ->sole();

    expect($movement->warehouse_id)->toBe($prepWarehouse->id)
        ->and($movement->user_id)->toBe($user->id)
        ->and($movement->operation)->toBe(InventoryMovementOperation::OUT)
        ->and((float) $movement->quantity)->toBe(2.5)
        ->and((float) $movement->unit_cost)->toBe(40.0)
        ->and((float) $movement->total_cost)->toBe(100.0)
        ->and($movement->referenceable_type)->toBe(StockIssue::class)
        ->and($movement->referenceable_id)->toBe($issue->id)
        ->and($movement->referenceable->destination)->toBe('حفلة أحمد');

    $daily = InventoryItemMovementDaily::query()
        ->where('product_id', $oil->id)
        ->where('warehouse_id', $prepWarehouse->id)
        ->whereNull('closed_at')
        ->sole();

    expect((float) $daily->issue_quantity)->toBe(2.5);
});

it('suggests previously used destinations trimmed and distinct', function () {
    $warehouse = Warehouse::default();

    StockIssue::create([
        'warehouse_id' => $warehouse->id,
        'destination' => '  وجبات الموظفين  ',
    ]);
    StockIssue::create([
        'warehouse_id' => $warehouse->id,
        'destination' => 'وجبات الموظفين',
    ]);
    StockIssue::create([
        'warehouse_id' => $warehouse->id,
        'destination' => 'ضيافة',
    ]);
    StockIssue::create([
        'warehouse_id' => $warehouse->id,
        'destination' => 'حفلة أحمد',
    ]);

    $suggestions = StockIssue::distinctDestinations();

    expect($suggestions)->toHaveCount(3)
        ->and($suggestions)->toContain('وجبات الموظفين', 'ضيافة', 'حفلة أحمد');
});

it('is not counted in sales, shift money or profit', function () {
    $user = User::factory()->admin()->create();
    actingAs($user);

    $today = Carbon::today()->toDateString();
    $shift = Shift::factory()->create([
        'user_id' => $user->id,
        'start_at' => now()->subHours(2),
        'end_at' => null,
        'closed' => false,
        'start_cash' => 500,
    ]);

    $menuProduct = Product::factory()->create([
        'type' => ProductType::Manufactured,
        'price' => 100,
        'cost' => 40,
    ]);

    $order = Order::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $user->id,
        'status' => OrderStatus::COMPLETED,
        'type' => 'takeaway',
        'sub_total' => 100,
        'total' => 100,
        'profit' => 60,
        'discount' => 0,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $menuProduct->id,
        'quantity' => 1,
        'price' => 100,
        'cost' => 40,
        'total' => 100,
    ]);

    Payment::factory()->create([
        'order_id' => $order->id,
        'shift_id' => $shift->id,
        'method' => PaymentMethod::CASH,
        'amount' => 100,
    ]);

    $shiftsReport = app(ShiftsReportService::class);
    $productsSalesReport = app(ProductsSalesReportService::class);
    $channelReport = app(ChannelPerformanceReportService::class);

    $shiftStatsBefore = $shiftsReport->calculateShiftStats($shift);
    $periodStatsBefore = $shiftsReport->calculatePeriodStats($today, $today, [$shift->id]);
    $salesSummaryBefore = $productsSalesReport->getPeriodSummary($today, $today, [$shift->id]);
    $channelFilters = ChannelReportFilters::fromArray(['startDate' => $today, 'endDate' => $today]);
    $channelTotalsBefore = $channelReport->totals($channelFilters, $channelReport->channelRows($channelFilters));

    // Confirm a stock issue
    $rice = Product::factory()->create([
        'type' => ProductType::RawMaterial,
        'cost' => 25,
    ]);
    openInventoryDay($rice);

    $issue = StockIssue::create([
        'warehouse_id' => Warehouse::default()->id,
        'destination' => 'وجبات الموظفين',
        'user_id' => $user->id,
    ]);
    $issue->items()->create([
        'product_id' => $rice->id,
        'quantity' => 4,
    ]);

    app(StockIssueService::class)->confirm($issue);

    $shiftStatsAfter = $shiftsReport->calculateShiftStats($shift->fresh());
    $periodStatsAfter = $shiftsReport->calculatePeriodStats($today, $today, [$shift->id]);
    $salesSummaryAfter = $productsSalesReport->getPeriodSummary($today, $today, [$shift->id]);
    $channelTotalsAfter = $channelReport->totals($channelFilters, $channelReport->channelRows($channelFilters));

    expect($shiftStatsAfter)->toBe($shiftStatsBefore)
        ->and($periodStatsAfter)->toBe($periodStatsBefore)
        ->and($salesSummaryAfter['total_sales'])->toBe($salesSummaryBefore['total_sales'])
        ->and($salesSummaryAfter['total_profit'])->toBe($salesSummaryBefore['total_profit'])
        ->and($salesSummaryAfter['total_quantity'])->toBe($salesSummaryBefore['total_quantity'])
        ->and($channelTotalsAfter)->toBe($channelTotalsBefore);
});

it('blocks closing the day while a draft issue exists', function () {
    $rice = Product::factory()->create(['type' => ProductType::RawMaterial, 'cost' => 25]);
    openInventoryDay($rice);

    $issue = StockIssue::factory()->create([
        'warehouse_id' => Warehouse::default()->id,
        'destination' => 'وجبات الموظفين',
        'closed_at' => null,
    ]);

    $aggregationService = app(InventoryDailyAggregationService::class);

    expect(fn () => $aggregationService->assertNoOpenDocuments())
        ->toThrow(Exception::class, 'لا يمكن إغلاق اليوم لوجود 1 إذن صرف مفتوح');

    expect(fn () => $aggregationService->closeDay())
        ->toThrow(Exception::class, 'لا يمكن إغلاق اليوم لوجود 1 إذن صرف مفتوح');

    $issue->items()->create([
        'product_id' => $rice->id,
        'quantity' => 1,
    ]);
    app(StockIssueService::class)->confirm($issue);

    expect($aggregationService->closeDay())->toBeGreaterThan(0);
});

it('makes confirmed issues read-only', function () {
    $user = User::factory()->admin()->create();
    actingAs($user);

    $rice = Product::factory()->create(['type' => ProductType::RawMaterial, 'cost' => 25]);
    openInventoryDay($rice);

    $issue = StockIssue::create([
        'warehouse_id' => Warehouse::default()->id,
        'destination' => 'وجبات الموظفين',
        'user_id' => $user->id,
    ]);
    $item = $issue->items()->create([
        'product_id' => $rice->id,
        'quantity' => 2,
    ]);

    app(StockIssueService::class)->confirm($issue);

    $issue->refresh();
    $item->refresh();

    expect(fn () => app(StockIssueService::class)->confirm($issue))
        ->toThrow(ValidationException::class);

    expect(fn () => $issue->update(['destination' => 'ضيافة']))
        ->toThrow(ValidationException::class);

    expect(fn () => $issue->delete())
        ->toThrow(ValidationException::class);

    expect(fn () => $item->update(['quantity' => 5]))
        ->toThrow(ValidationException::class);

    expect(fn () => $item->delete())
        ->toThrow(ValidationException::class);
});

it('manages stock issues through Filament resource and registers permissions', function () {
    $catalog = RolesAndPermissionsSeeder::permissionsCatalog();
    $inventoryPermissions = $catalog['المشتريات والمخزون'] ?? [];

    expect($inventoryPermissions)->toHaveKeys([
        'view_stock_issues',
        'create_stock_issues',
        'update_stock_issues',
        'delete_stock_issues',
    ]);

    $this->seed(RolesAndPermissionsSeeder::class);
    $viewer = User::factory()->viewer()->create();
    $policy = Gate::getPolicyFor(StockIssue::class);
    expect($policy)->toBeInstanceOf(StockIssuePolicy::class)
        ->and($policy->create($viewer))->toBeFalse();
    $viewer->givePermissionTo('create_stock_issues');
    expect($policy->create($viewer))->toBeTrue();

    $admin = User::factory()->admin()->create();
    actingAs($admin);

    $rice = Product::factory()->create(['name' => 'أرز', 'type' => ProductType::RawMaterial, 'cost' => 25]);
    openInventoryDay($rice);

    livewire(ListStockIssues::class)
        ->assertSuccessful();

    $undoRepeaterFake = Repeater::fake();

    livewire(CreateStockIssue::class)
        ->assertSuccessful()
        ->fillForm([
            'warehouse_id' => Warehouse::default()->id,
            'destination' => '  وجبات الموظفين  ',
            'user_id' => $admin->id,
            'notes' => 'صرف تجريبي',
            'items' => [
                [
                    'product_id' => $rice->id,
                    'quantity' => 3,
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $undoRepeaterFake();

    $issue = StockIssue::where('destination', 'وجبات الموظفين')->sole();
    expect($issue->items)->toHaveCount(1);

    livewire(EditStockIssue::class, ['record' => $issue->getRouteKey()])
        ->assertSuccessful();

    livewire(ViewStockIssue::class, ['record' => $issue->getRouteKey()])
        ->assertSuccessful()
        ->callAction('confirm');

    expect($issue->fresh()->closed_at)->not->toBeNull()
        ->and((float) $issue->fresh()->total_cost)->toBe(75.0);

    // Migration down() and up() work cleanly
    $migration = require database_path('migrations/2026_11_10_320001_create_stock_issues_tables.php');
    $migration->down();
    expect(Schema::hasTable('stock_issue_items'))->toBeFalse()
        ->and(Schema::hasTable('stock_issues'))->toBeFalse();
    $migration->up();
    expect(Schema::hasTable('stock_issues'))->toBeTrue()
        ->and(Schema::hasTable('stock_issue_items'))->toBeTrue();
});

it('refuses a second confirm of the same issue loaded before the first confirm', function () {
    $product = Product::factory()->create(['type' => ProductType::RawMaterial, 'cost' => 25]);
    $issue = StockIssue::factory()->create(['destination' => 'وجبات الموظفين']);
    $issue->items()->create(['product_id' => $product->id, 'quantity' => 3]);
    openInventoryDay($product);

    $staleCopy = StockIssue::find($issue->id);
    app(StockIssueService::class)->confirm($issue);

    expect(fn () => app(StockIssueService::class)->confirm($staleCopy))
        ->toThrow(ValidationException::class);
    expect(InventoryItemMovement::where('referenceable_id', $issue->id)
        ->where('reason', MovementReason::STOCK_ISSUE)->count())->toBe(1);
});
