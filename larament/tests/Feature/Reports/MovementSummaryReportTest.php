<?php

use App\Enums\InventoryMovementOperation;
use App\Enums\MovementReason;
use App\Enums\ProductType;
use App\Filament\Pages\Reports\MovementSummaryReport;
use App\Filament\Widgets\MovementSummaryReportTable;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\InventoryItemMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Waste;
use App\Services\InventoryDailyAggregationService;
use App\Services\Reports\Inventory\MovementSummaryReportService;
use App\Services\Reports\StockReportService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

it('totals quantity and value per document type for Main in November', function () {
    $user = User::factory()->admin()->create();
    actingAs($user);

    $main = Warehouse::default();
    $otherWarehouse = Warehouse::factory()->create(['name' => 'مخزن البار']);

    $mainCategory = Category::factory()->create(['name' => 'الخامات الأساسية', 'parent_id' => null]);
    $subCategory = Category::factory()->create(['name' => 'لحوم ودواجن', 'parent_id' => $mainCategory->id]);

    $chicken = Product::factory()->create([
        'name' => 'دجاج',
        'category_id' => $subCategory->id,
        'type' => ProductType::RawMaterial,
        'cost' => 100,
    ]);

    $movements = [
        [MovementReason::PURCHASE, InventoryMovementOperation::IN, 850, 100, 85000],
        [MovementReason::PURCHASE_RETURN, InventoryMovementOperation::OUT, 12, 100, 1200],
        [MovementReason::ORDER, InventoryMovementOperation::OUT, 120, 100, 12000],
        [MovementReason::ORDER_RETURN, InventoryMovementOperation::IN, 5, 100, 500],
        [MovementReason::WASTE, InventoryMovementOperation::OUT, 9, 100, 900],
        [MovementReason::STOCKTAKING, InventoryMovementOperation::IN, 2, 100, 200],
        [MovementReason::STOCKTAKING, InventoryMovementOperation::OUT, 8.5, 100, 850],
        [MovementReason::TRANSFER_IN, InventoryMovementOperation::IN, 15, 100, 1500],
        [MovementReason::TRANSFER_OUT, InventoryMovementOperation::OUT, 400, 100, 40000],
        [MovementReason::MANUFACTURING_IN, InventoryMovementOperation::IN, 60, 100, 6000],
        [MovementReason::MANUFACTURING_OUT, InventoryMovementOperation::OUT, 300, 100, 30000],
        [MovementReason::STOCK_ISSUE, InventoryMovementOperation::OUT, 43.2, 100, 4320],
    ];

    foreach ($movements as $idx => [$reason, $op, $qty, $unitCost, $totalCost]) {
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00')->addMinutes($idx));
        InventoryItemMovement::create([
            'product_id' => $chicken->id,
            'warehouse_id' => $main->id,
            'user_id' => $user->id,
            'operation' => $op,
            'reason' => $reason,
            'quantity' => $qty,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
        ]);
    }

    // Noise in another warehouse and another month (must be excluded)
    Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00'));
    InventoryItemMovement::create([
        'product_id' => $chicken->id,
        'warehouse_id' => $otherWarehouse->id,
        'user_id' => $user->id,
        'operation' => InventoryMovementOperation::IN,
        'reason' => MovementReason::PURCHASE,
        'quantity' => 999,
        'unit_cost' => 100,
        'total_cost' => 99900,
    ]);

    Carbon::setTestNow(Carbon::parse('2026-10-31 23:00:00'));
    InventoryItemMovement::create([
        'product_id' => $chicken->id,
        'warehouse_id' => $main->id,
        'user_id' => $user->id,
        'operation' => InventoryMovementOperation::IN,
        'reason' => MovementReason::PURCHASE,
        'quantity' => 555,
        'unit_cost' => 100,
        'total_cost' => 55500,
    ]);

    $summary = app(MovementSummaryReportService::class)->rows(
        '2026-11-01',
        '2026-11-30',
        $main->id,
        $mainCategory->id,
        $subCategory->id,
        $chicken->id,
    );

    expect($summary['purchase']->quantity)->toBe(850.0)
        ->and($summary['purchase']->value)->toBe(85000.0)
        ->and($summary['purchase_return']->quantity)->toBe(12.0)
        ->and($summary['purchase_return']->value)->toBe(1200.0)
        ->and($summary['order']->quantity)->toBe(120.0)
        ->and($summary['order']->value)->toBe(12000.0)
        ->and($summary['order_return']->quantity)->toBe(5.0)
        ->and($summary['order_return']->value)->toBe(500.0)
        ->and($summary['waste']->quantity)->toBe(9.0)
        ->and($summary['waste']->value)->toBe(900.0)
        ->and($summary['stocktaking_in']->quantity)->toBe(2.0)
        ->and($summary['stocktaking_in']->value)->toBe(200.0)
        ->and($summary['stocktaking_out']->quantity)->toBe(8.5)
        ->and($summary['stocktaking_out']->value)->toBe(850.0)
        ->and($summary['transfer_in']->quantity)->toBe(15.0)
        ->and($summary['transfer_in']->value)->toBe(1500.0)
        ->and($summary['transfer_out']->quantity)->toBe(400.0)
        ->and($summary['transfer_out']->value)->toBe(40000.0)
        ->and($summary['manufacturing_in']->quantity)->toBe(60.0)
        ->and($summary['manufacturing_in']->value)->toBe(6000.0)
        ->and($summary['manufacturing_out']->quantity)->toBe(300.0)
        ->and($summary['manufacturing_out']->value)->toBe(30000.0)
        ->and($summary['stock_issue']->quantity)->toBe(43.2)
        ->and($summary['stock_issue']->value)->toBe(4320.0);
});

it('quantities match the stock report for the same filters', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-10 14:00:00'));

    $user = User::factory()->admin()->create();
    actingAs($user);

    $main = Warehouse::default();
    $mainCategory = Category::factory()->create(['name' => 'المخزون العام', 'parent_id' => null]);
    $subCategory = Category::factory()->create(['name' => 'خامات المطبخ', 'parent_id' => $mainCategory->id]);

    $product = Product::factory()->create([
        'name' => 'زيت ذرة',
        'category_id' => $subCategory->id,
        'type' => ProductType::RawMaterial,
        'cost' => 50,
    ]);

    InventoryItem::updateOrCreate(
        ['product_id' => $product->id, 'warehouse_id' => $main->id],
        ['quantity' => 100],
    );

    app(InventoryDailyAggregationService::class)->openDay();

    $entries = [
        [MovementReason::PURCHASE, InventoryMovementOperation::IN, 40],
        [MovementReason::PURCHASE_RETURN, InventoryMovementOperation::OUT, 3],
        [MovementReason::ORDER, InventoryMovementOperation::OUT, 18],
        [MovementReason::ORDER_RETURN, InventoryMovementOperation::IN, 2],
        [MovementReason::WASTE, InventoryMovementOperation::OUT, 4],
        [MovementReason::TRANSFER_IN, InventoryMovementOperation::IN, 12],
        [MovementReason::TRANSFER_OUT, InventoryMovementOperation::OUT, 7],
        [MovementReason::MANUFACTURING_IN, InventoryMovementOperation::IN, 15],
        [MovementReason::MANUFACTURING_OUT, InventoryMovementOperation::OUT, 9],
        [MovementReason::STOCK_ISSUE, InventoryMovementOperation::OUT, 5],
    ];

    foreach ($entries as $idx => [$reason, $op, $qty]) {
        InventoryItemMovement::create([
            'product_id' => $product->id,
            'warehouse_id' => $main->id,
            'user_id' => $user->id,
            'operation' => $op,
            'reason' => $reason,
            'quantity' => $qty,
            'unit_cost' => 50,
            'total_cost' => $qty * 50,
            'created_at' => Carbon::now()->addSeconds($idx + 1),
        ]);
    }

    app(InventoryDailyAggregationService::class)->bulkAggregateWithInsertSelect([$product->id]);

    $summary = MovementSummaryReportService::rows(
        '2026-11-10',
        '2026-11-10',
        $main->id,
        $mainCategory->id,
        $subCategory->id,
        $product->id,
    );

    $stockRow = StockReportService::query('2026-11-10', '2026-11-10', true, $main->id)
        ->where('products.id', $product->id)
        ->first();

    expect($stockRow)->not->toBeNull();

    // purchase = incoming
    expect($summary['purchase']->quantity)->toBe((float) $stockRow->incoming)
        // order − order_return = sales
        ->and(round($summary['order']->quantity - $summary['order_return']->quantity, 3))->toBe((float) $stockRow->sales)
        // waste + purchase_return = return_waste
        ->and(round($summary['waste']->quantity + $summary['purchase_return']->quantity, 3))->toBe((float) $stockRow->return_waste)
        // transfer_in / transfer_out
        ->and($summary['transfer_in']->quantity)->toBe((float) $stockRow->transfer_in)
        ->and($summary['transfer_out']->quantity)->toBe((float) $stockRow->transfer_out)
        // manufacturing_in / manufacturing_out
        ->and($summary['manufacturing_in']->quantity)->toBe((float) $stockRow->manufacturing_in)
        ->and($summary['manufacturing_out']->quantity)->toBe((float) $stockRow->manufacturing_out)
        // stock_issue = issue
        ->and($summary['stock_issue']->quantity)->toBe((float) $stockRow->issue);
});

it('drills down to the documents behind a line', function () {
    $user = User::factory()->admin()->create(['name' => 'محمود المشتريات']);
    actingAs($user);

    $main = Warehouse::default();
    $rice = Product::factory()->create(['name' => 'أرز', 'type' => ProductType::RawMaterial, 'cost' => 30]);
    $oil = Product::factory()->create(['name' => 'زيت', 'type' => ProductType::RawMaterial, 'cost' => 60]);

    $supplier = Supplier::factory()->create();
    $invoice1 = PurchaseInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'warehouse_id' => $main->id,
        'user_id' => $user->id,
        'total' => 900,
    ]);
    $invoice2 = PurchaseInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'warehouse_id' => $main->id,
        'user_id' => $user->id,
        'total' => 600,
    ]);

    // Invoice 1 has two movements (rice + oil)
    Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00'));
    InventoryItemMovement::create([
        'product_id' => $rice->id,
        'warehouse_id' => $main->id,
        'user_id' => $user->id,
        'operation' => InventoryMovementOperation::IN,
        'reason' => MovementReason::PURCHASE,
        'quantity' => 10,
        'unit_cost' => 30,
        'total_cost' => 300,
        'referenceable_type' => PurchaseInvoice::class,
        'referenceable_id' => $invoice1->id,
    ]);
    InventoryItemMovement::create([
        'product_id' => $oil->id,
        'warehouse_id' => $main->id,
        'user_id' => $user->id,
        'operation' => InventoryMovementOperation::IN,
        'reason' => MovementReason::PURCHASE,
        'quantity' => 10,
        'unit_cost' => 60,
        'total_cost' => 600,
        'referenceable_type' => PurchaseInvoice::class,
        'referenceable_id' => $invoice1->id,
    ]);

    // Invoice 2 has one movement (oil)
    Carbon::setTestNow(Carbon::parse('2026-11-06 11:00:00'));
    InventoryItemMovement::create([
        'product_id' => $oil->id,
        'warehouse_id' => $main->id,
        'user_id' => $user->id,
        'operation' => InventoryMovementOperation::IN,
        'reason' => MovementReason::PURCHASE,
        'quantity' => 10,
        'unit_cost' => 60,
        'total_cost' => 600,
        'referenceable_type' => PurchaseInvoice::class,
        'referenceable_id' => $invoice2->id,
    ]);

    $summary = MovementSummaryReportService::rows('2026-11-01', '2026-11-30', $main->id);
    $purchaseRow = $summary['purchase'];

    expect($purchaseRow->quantity)->toBe(30.0)
        ->and($purchaseRow->value)->toBe(1500.0)
        ->and($purchaseRow->documents)->toHaveCount(2);

    $docs = $purchaseRow->documents->values();

    expect($docs[0]->referenceable_id)->toBe($invoice1->id)
        ->and($docs[0]->document_type)->toBe('فاتورة شراء')
        ->and($docs[0]->document_number)->toBe((string) $invoice1->id)
        ->and($docs[0]->document_url)->toBe(route('filament.admin.resources.purchase-invoices.view', ['record' => $invoice1->id]))
        ->and($docs[0]->quantity)->toBe(20.0)
        ->and($docs[0]->value)->toBe(900.0)
        ->and($docs[0]->user_name)->toBe('محمود المشتريات');

    expect($docs[1]->referenceable_id)->toBe($invoice2->id)
        ->and($docs[1]->quantity)->toBe(10.0)
        ->and($docs[1]->value)->toBe(600.0);
});

it('registers permission and renders the movement summary report page and table widget with shared filters', function () {
    $catalog = RolesAndPermissionsSeeder::permissionsCatalog();
    expect($catalog['التقارير'] ?? [])->toHaveKey('view_movement_summary_report');

    $mainCategory = Category::factory()->create(['name' => 'المشروبات', 'parent_id' => null]);
    $subCategory = Category::factory()->create(['name' => 'عصائر', 'parent_id' => $mainCategory->id]);
    $product = Product::factory()->create([
        'name' => 'مانجو خام',
        'category_id' => $subCategory->id,
        'type' => ProductType::RawMaterial,
        'cost' => 45,
    ]);

    $admin = User::factory()->admin()->create();
    actingAs($admin);

    livewire(MovementSummaryReport::class)
        ->assertSuccessful()
        ->assertSchemaComponentExists('presetPeriod', 'filtersForm')
        ->assertSchemaComponentExists('startDate', 'filtersForm')
        ->assertSchemaComponentExists('endDate', 'filtersForm')
        ->assertSchemaComponentExists('warehouse_id', 'filtersForm')
        ->assertSchemaComponentExists('main_category_id', 'filtersForm')
        ->assertSchemaComponentExists('sub_category_id', 'filtersForm')
        ->assertSchemaComponentExists('product_id', 'filtersForm');

    livewire(MovementSummaryReportTable::class, [
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
