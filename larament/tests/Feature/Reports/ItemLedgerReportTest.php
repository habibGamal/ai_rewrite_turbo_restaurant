<?php

use App\Enums\InventoryMovementOperation;
use App\Enums\MovementReason;
use App\Enums\ProductType;
use App\Filament\Pages\Reports\ItemLedgerReport;
use App\Filament\Widgets\ItemLedgerReportTable;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\InventoryItemMovement;
use App\Models\ManufacturingOrder;
use App\Models\ManufacturingRecipe;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\ReturnPurchaseInvoice;
use App\Models\StockIssue;
use App\Models\Stocktaking;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Waste;
use App\Services\Reports\Inventory\ItemLedgerReportService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

it('lists 11/01 purchase +20 (bal 20), 11/02 manufacturing −10 (bal 10), 11/03 waste −0.5 (bal 9.5) with values 1400 / 700 / 35', function () {
    $user = User::factory()->admin()->create(['name' => 'أحمد المخزنجي']);
    actingAs($user);

    $main = Warehouse::default();
    $chicken = Product::factory()->create([
        'name' => 'دجاج خام',
        'type' => ProductType::RawMaterial,
        'cost' => 70.00,
    ]);

    $supplier = Supplier::factory()->create();
    $invoice = PurchaseInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'warehouse_id' => $main->id,
        'user_id' => $user->id,
        'total' => 1400,
        'closed_at' => Carbon::parse('2026-11-01 10:00:00'),
    ]);

    $recipe = ManufacturingRecipe::create([
        'name' => 'تتبيل دجاج',
        'is_active' => true,
    ]);
    $manufacturingOrder = ManufacturingOrder::create([
        'manufacturing_recipe_id' => $recipe->id,
        'source_warehouse_id' => $main->id,
        'destination_warehouse_id' => $main->id,
        'planned_main_quantity' => 10,
        'user_id' => $user->id,
        'closed_at' => Carbon::parse('2026-11-02 12:00:00'),
        'total_input_cost' => 700,
    ]);

    $waste = Waste::factory()->create([
        'warehouse_id' => $main->id,
        'user_id' => $user->id,
        'total' => 35,
        'closed_at' => Carbon::parse('2026-11-03 15:00:00'),
    ]);

    Carbon::setTestNow(Carbon::parse('2026-11-01 10:00:00'));
    InventoryItemMovement::create([
        'product_id' => $chicken->id,
        'warehouse_id' => $main->id,
        'user_id' => $user->id,
        'operation' => InventoryMovementOperation::IN,
        'reason' => MovementReason::PURCHASE,
        'quantity' => 20,
        'unit_cost' => 70,
        'total_cost' => 1400,
        'referenceable_type' => PurchaseInvoice::class,
        'referenceable_id' => $invoice->id,
    ]);

    Carbon::setTestNow(Carbon::parse('2026-11-02 12:00:00'));
    InventoryItemMovement::create([
        'product_id' => $chicken->id,
        'warehouse_id' => $main->id,
        'user_id' => $user->id,
        'operation' => InventoryMovementOperation::OUT,
        'reason' => MovementReason::MANUFACTURING_OUT,
        'quantity' => 10,
        'unit_cost' => 70,
        'total_cost' => 700,
        'referenceable_type' => ManufacturingOrder::class,
        'referenceable_id' => $manufacturingOrder->id,
    ]);

    Carbon::setTestNow(Carbon::parse('2026-11-03 15:00:00'));
    InventoryItemMovement::create([
        'product_id' => $chicken->id,
        'warehouse_id' => $main->id,
        'user_id' => $user->id,
        'operation' => InventoryMovementOperation::OUT,
        'reason' => MovementReason::WASTE,
        'quantity' => 0.5,
        'unit_cost' => 70,
        'total_cost' => 35,
        'referenceable_type' => Waste::class,
        'referenceable_id' => $waste->id,
    ]);

    $rows = app(ItemLedgerReportService::class)->rows(
        $chicken->id,
        $main->id,
        '2026-11-01',
        '2026-11-03'
    );

    expect($rows)->toHaveCount(3);

    expect($rows[0]->date)->toBe('2026-11-01')
        ->and($rows[0]->document_type)->toBe('فاتورة شراء')
        ->and($rows[0]->in)->toBe(20.0)
        ->and($rows[0]->out)->toBe(0.0)
        ->and($rows[0]->running_balance)->toBe(20.0)
        ->and($rows[0]->unit_cost)->toBe(70.0)
        ->and($rows[0]->value)->toBe(1400.0)
        ->and($rows[0]->user_name)->toBe('أحمد المخزنجي')
        ->and($rows[0]->warehouse_name)->toBe($main->name);

    expect($rows[1]->date)->toBe('2026-11-02')
        ->and($rows[1]->document_type)->toBe('أمر تصنيع')
        ->and($rows[1]->in)->toBe(0.0)
        ->and($rows[1]->out)->toBe(10.0)
        ->and($rows[1]->running_balance)->toBe(10.0)
        ->and($rows[1]->unit_cost)->toBe(70.0)
        ->and($rows[1]->value)->toBe(700.0);

    expect($rows[2]->date)->toBe('2026-11-03')
        ->and($rows[2]->document_type)->toBe('سجل تالف')
        ->and($rows[2]->in)->toBe(0.0)
        ->and($rows[2]->out)->toBe(0.5)
        ->and($rows[2]->running_balance)->toBe(9.5)
        ->and($rows[2]->unit_cost)->toBe(70.0)
        ->and($rows[2]->value)->toBe(35.0);
});

it('starts the running balance from the quantity before the date range', function () {
    $user = User::factory()->admin()->create();
    actingAs($user);

    $main = Warehouse::default();
    $grill = Warehouse::factory()->create(['name' => 'مخزن الشوايا']);
    $product = Product::factory()->create([
        'type' => ProductType::RawMaterial,
        'cost' => 50,
    ]);

    // Movements before 2026-11-01 in Main: +15 then -3 = +12 opening balance
    Carbon::setTestNow(Carbon::parse('2026-10-25 09:00:00'));
    InventoryItemMovement::create([
        'product_id' => $product->id,
        'warehouse_id' => $main->id,
        'user_id' => $user->id,
        'operation' => InventoryMovementOperation::IN,
        'reason' => MovementReason::PURCHASE,
        'quantity' => 15,
        'unit_cost' => 50,
        'total_cost' => 750,
    ]);

    Carbon::setTestNow(Carbon::parse('2026-10-28 11:00:00'));
    InventoryItemMovement::create([
        'product_id' => $product->id,
        'warehouse_id' => $main->id,
        'user_id' => $user->id,
        'operation' => InventoryMovementOperation::OUT,
        'reason' => MovementReason::WASTE,
        'quantity' => 3,
        'unit_cost' => 50,
        'total_cost' => 150,
    ]);

    // Movement before 2026-11-01 in Grill: +5
    Carbon::setTestNow(Carbon::parse('2026-10-30 14:00:00'));
    InventoryItemMovement::create([
        'product_id' => $product->id,
        'warehouse_id' => $grill->id,
        'user_id' => $user->id,
        'operation' => InventoryMovementOperation::IN,
        'reason' => MovementReason::TRANSFER_IN,
        'quantity' => 5,
        'unit_cost' => 50,
        'total_cost' => 250,
    ]);

    // Movements in range (2026-11-01 to 2026-11-05)
    Carbon::setTestNow(Carbon::parse('2026-11-01 10:00:00'));
    InventoryItemMovement::create([
        'product_id' => $product->id,
        'warehouse_id' => $main->id,
        'user_id' => $user->id,
        'operation' => InventoryMovementOperation::IN,
        'reason' => MovementReason::PURCHASE,
        'quantity' => 20,
        'unit_cost' => 50,
        'total_cost' => 1000,
    ]);

    Carbon::setTestNow(Carbon::parse('2026-11-02 10:00:00'));
    InventoryItemMovement::create([
        'product_id' => $product->id,
        'warehouse_id' => $main->id,
        'user_id' => $user->id,
        'operation' => InventoryMovementOperation::OUT,
        'reason' => MovementReason::STOCK_ISSUE,
        'quantity' => 4,
        'unit_cost' => 50,
        'total_cost' => 200,
    ]);

    // Scoped to Main warehouse: opening = 12, then +20 => 32, then -4 => 28
    $mainRows = ItemLedgerReportService::rows($product->id, $main->id, '2026-11-01', '2026-11-05');

    expect($mainRows)->toHaveCount(2)
        ->and($mainRows[0]->opening_balance)->toBe(12.0)
        ->and($mainRows[0]->running_balance)->toBe(32.0)
        ->and($mainRows[1]->running_balance)->toBe(28.0);

    // Scoped to All warehouses (null): opening = 12 + 5 = 17, then +20 => 37, then -4 => 33
    $allRows = ItemLedgerReportService::rows($product->id, null, '2026-11-01', '2026-11-05');

    expect($allRows)->toHaveCount(2)
        ->and($allRows[0]->opening_balance)->toBe(17.0)
        ->and($allRows[0]->running_balance)->toBe(37.0)
        ->and($allRows[1]->running_balance)->toBe(33.0);
});

it('shows the user and a link to each document and falls back gracefully for StockTransfer and unknown types', function () {
    $user = User::factory()->admin()->create(['name' => 'سارة المشرفة']);
    actingAs($user);

    $main = Warehouse::default();
    $product = Product::factory()->create([
        'type' => ProductType::RawMaterial,
        'cost' => 30,
    ]);

    $supplier = Supplier::factory()->create();
    $purchase = PurchaseInvoice::factory()->create(['supplier_id' => $supplier->id, 'warehouse_id' => $main->id, 'user_id' => $user->id]);
    $returnPurchase = ReturnPurchaseInvoice::factory()->create(['supplier_id' => $supplier->id, 'warehouse_id' => $main->id, 'user_id' => $user->id]);
    $waste = Waste::factory()->create(['warehouse_id' => $main->id, 'user_id' => $user->id]);
    $stocktaking = Stocktaking::factory()->create(['warehouse_id' => $main->id, 'user_id' => $user->id]);
    $order = Order::factory()->create(['user_id' => $user->id]);
    $recipe = ManufacturingRecipe::create(['name' => 'وصفة', 'is_active' => true]);
    $manufacturingOrder = ManufacturingOrder::create([
        'manufacturing_recipe_id' => $recipe->id,
        'source_warehouse_id' => $main->id,
        'destination_warehouse_id' => $main->id,
        'planned_main_quantity' => 5,
        'user_id' => $user->id,
    ]);
    $stockIssue = StockIssue::create([
        'warehouse_id' => $main->id,
        'destination' => 'وجبات الموظفين',
        'user_id' => $user->id,
    ]);

    $docs = [
        [PurchaseInvoice::class, $purchase->id, MovementReason::PURCHASE, InventoryMovementOperation::IN, 'فاتورة شراء', route('filament.admin.resources.purchase-invoices.view', ['record' => $purchase->id])],
        [ReturnPurchaseInvoice::class, $returnPurchase->id, MovementReason::PURCHASE_RETURN, InventoryMovementOperation::OUT, 'مرتجع شراء', route('filament.admin.resources.return-purchase-invoices.view', ['record' => $returnPurchase->id])],
        [Waste::class, $waste->id, MovementReason::WASTE, InventoryMovementOperation::OUT, 'سجل تالف', route('filament.admin.resources.wastes.view', ['record' => $waste->id])],
        [Stocktaking::class, $stocktaking->id, MovementReason::STOCKTAKING, InventoryMovementOperation::IN, 'جرد', route('filament.admin.resources.stocktakings.view', ['record' => $stocktaking->id])],
        [Order::class, $order->id, MovementReason::ORDER, InventoryMovementOperation::OUT, 'طلب', route('filament.admin.resources.orders.view', ['record' => $order->id])],
        [ManufacturingOrder::class, $manufacturingOrder->id, MovementReason::MANUFACTURING_IN, InventoryMovementOperation::IN, 'أمر تصنيع', route('filament.admin.resources.manufacturing-orders.view', ['record' => $manufacturingOrder->id])],
        [StockIssue::class, $stockIssue->id, MovementReason::STOCK_ISSUE, InventoryMovementOperation::OUT, 'إذن صرف', route('filament.admin.resources.stock-issues.view', ['record' => $stockIssue->id])],
        ['App\\Models\\StockTransfer', 77, MovementReason::TRANSFER_OUT, InventoryMovementOperation::OUT, 'إذن تحويل', null],
        ['App\\Models\\UnknownCustomDocument', 99, MovementReason::ADJUSTMENT, InventoryMovementOperation::IN, 'مستند مخزني', null],
    ];

    foreach ($docs as $index => [$refType, $refId, $reason, $op, $expectedLabel, $expectedUrl]) {
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00')->addMinutes($index));
        InventoryItemMovement::create([
            'product_id' => $product->id,
            'warehouse_id' => $main->id,
            'user_id' => $user->id,
            'operation' => $op,
            'reason' => $reason,
            'quantity' => 1,
            'unit_cost' => 30,
            'total_cost' => 30,
            'referenceable_type' => $refType,
            'referenceable_id' => $refId,
        ]);
    }

    $rows = ItemLedgerReportService::rows($product->id, $main->id, '2026-11-05', '2026-11-05');

    expect($rows)->toHaveCount(count($docs));

    foreach ($docs as $index => [$refType, $refId, $reason, $op, $expectedLabel, $expectedUrl]) {
        expect($rows[$index]->document_type)->toBe($expectedLabel)
            ->and($rows[$index]->document_number)->toBe((string) $refId)
            ->and($rows[$index]->document_url)->toBe($expectedUrl)
            ->and($rows[$index]->user_name)->toBe('سارة المشرفة');
    }
});

it('registers permission and renders the item ledger report page and table widget with shared filters', function () {
    $catalog = RolesAndPermissionsSeeder::permissionsCatalog();
    expect($catalog['التقارير'] ?? [])->toHaveKey('view_item_ledger_report');

    $mainCategory = Category::factory()->create(['name' => 'اللحوم والدواجن', 'parent_id' => null]);
    $subCategory = Category::factory()->create(['name' => 'الدواجن', 'parent_id' => $mainCategory->id]);
    $product = Product::factory()->create([
        'name' => 'صدور دجاج',
        'category_id' => $subCategory->id,
        'type' => ProductType::RawMaterial,
        'cost' => 95,
    ]);
    InventoryItem::updateOrCreate(['product_id' => $product->id, 'warehouse_id' => Warehouse::default()->id], ['quantity' => 10]);

    $admin = User::factory()->admin()->create();
    actingAs($admin);

    livewire(ItemLedgerReport::class)
        ->assertSuccessful()
        ->assertSchemaComponentExists('presetPeriod', 'filtersForm')
        ->assertSchemaComponentExists('startDate', 'filtersForm')
        ->assertSchemaComponentExists('endDate', 'filtersForm')
        ->assertSchemaComponentExists('warehouse_id', 'filtersForm')
        ->assertSchemaComponentExists('main_category_id', 'filtersForm')
        ->assertSchemaComponentExists('sub_category_id', 'filtersForm')
        ->assertSchemaComponentExists('product_id', 'filtersForm');

    livewire(ItemLedgerReportTable::class, [
        'pageFilters' => [
            'startDate' => '2026-11-01',
            'endDate' => '2026-11-30',
            'warehouse_id' => Warehouse::default()->id,
            'main_category_id' => $mainCategory->id,
            'sub_category_id' => $subCategory->id,
            'product_id' => $product->id,
        ],
    ])
        ->assertSuccessful()
        ->assertTableActionExists('export');
});
