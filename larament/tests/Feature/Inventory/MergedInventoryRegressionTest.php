<?php

use App\Enums\MovementReason;
use App\Enums\OrderStatus;
use App\Filament\Actions\ConfirmStockIssueAction;
use App\Filament\Exports\ItemLedgerReportExporter;
use App\Filament\Resources\StockIssues\StockIssueResource;
use App\Filament\Resources\Stocktakings\Pages\CreateStocktaking;
use App\Filament\Widgets\ItemLedgerReportTable;
use App\Filament\Widgets\OrderTypePerformanceWidget;
use App\Filament\Widgets\ProductsSalesStatsWidget;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\InventoryItemMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCostHistory;
use App\Models\PurchaseInvoice;
use App\Models\ReturnPurchaseInvoice;
use App\Models\Shift;
use App\Models\StockIssue;
use App\Models\Stocktaking;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Waste;
use App\Services\Manufacturing\ManufacturingOrderService;
use App\Services\Manufacturing\ManufacturingRecipeService;
use App\Services\Orders\OrderReturnService;
use App\Services\Orders\OrderService;
use App\Services\ProductsSalesReportService;
use App\Services\PurchaseService;
use App\Services\Reports\Inventory\CostHistoryReportService;
use App\Services\Reports\Inventory\ItemLedgerReportService;
use App\Services\Reports\Inventory\MovementSummaryReportService;
use App\Services\Reports\StockReportService;
use App\Services\SettingsService;
use App\Services\StockIssueService;
use App\Services\StockService;
use App\Services\StocktakingService;
use App\Services\WasteService;
use Carbon\Carbon;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Facades\Schema;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-11-10 18:00:00');
    app(SettingsService::class)->set('costing_method', 'weighted_average');
});

afterEach(fn () => Carbon::setTestNow());

function mergedStock(float $cost = 20, float $quantity = 10, ?Warehouse $warehouse = null): Product
{
    $product = Product::factory()->create(['type' => 'consumable', 'cost' => $cost, 'warehouse_id' => $warehouse?->id]);
    InventoryItem::updateOrCreate(['product_id' => $product->id, 'warehouse_id' => $warehouse?->id ?? Warehouse::default()->id], ['quantity' => $quantity]);
    $day = openInventoryDay($product);
    $day->update(['start_quantity' => $warehouse ? 0 : $quantity]);

    return $product;
}

function mergedOrder(Product $product, float $quantity = 2, float $total = 200): Order
{
    $shift = Shift::factory()->active()->create(['user_id' => auth()->id()]);
    $order = Order::factory()->create(['user_id' => auth()->id(), 'shift_id' => $shift->id, 'type' => 'takeaway', 'status' => OrderStatus::PROCESSING, 'discount' => 0, 'temp_discount_percent' => 0]);
    $order->items()->create(['product_id' => $product->id, 'quantity' => $quantity, 'price' => $total / $quantity, 'cost' => $product->cost, 'total' => $total]);

    return $order;
}

it('requires policy permission as well as draft state for stock issue mutations', function () {
    $product = mergedStock();
    $issue = StockIssue::factory()->create();
    $issue->items()->create(['product_id' => $product->id, 'quantity' => 3]);
    $user = User::factory()->watcher()->create();
    $user->givePermissionTo('view_stock_issues');
    $this->actingAs($user);
    expect(StockIssueResource::canEdit($issue))->toBeFalse()
        ->and(StockIssueResource::canDelete($issue))->toBeFalse();
    $action = ConfirmStockIssueAction::table()->record($issue);
    expect($action->isVisible())->toBeFalse();
    expect(fn () => ($action->getActionFunction())($issue))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(InventoryItemMovement::count())->toBe(0);
    $user->givePermissionTo(['update_stock_issues', 'delete_stock_issues']);
    expect(StockIssueResource::canEdit($issue))->toBeTrue()->and(StockIssueResource::canDelete($issue))->toBeTrue();
});

it('rejects a stale second close before posting stock again', function (string $kind) {
    $product = mergedStock();
    $document = match ($kind) {
        'purchase' => PurchaseInvoice::factory()->create(),
        'return' => ReturnPurchaseInvoice::factory()->create(),
        'waste' => Waste::factory()->create(['user_id' => auth()->id()]),
        'stocktaking' => Stocktaking::factory()->create(['user_id' => auth()->id()]),
    };
    $data = ['product_id' => $product->id, 'quantity' => 2, 'price' => 30, 'total' => 60];
    if ($kind === 'stocktaking') {
        $data = ['product_id' => $product->id, 'stock_quantity' => 10, 'real_quantity' => 8, 'price' => 20, 'total' => -40];
    }
    $document->items()->create($data);
    $stale = $document->fresh();
    $close = match ($kind) {
        'purchase' => fn ($doc) => app(PurchaseService::class)->closePurchaseInvoice($doc),
        'return' => fn ($doc) => app(PurchaseService::class)->closeReturnPurchaseInvoice($doc),
        'waste' => fn ($doc) => app(WasteService::class)->closeWaste($doc),
        'stocktaking' => fn ($doc) => app(StocktakingService::class)->closeStocktaking($doc),
    };
    $close($document);
    expect(fn () => $close($stale))->toThrow(Exception::class);
    expect(InventoryItemMovement::count())->toBe(1)
        ->and(app(StockService::class)->getCurrentStock($product->id))->toBe($kind === 'purchase' ? 12.0 : 8.0);
})->with(['purchase', 'return', 'waste', 'stocktaking']);

it('restores only outstanding sale stock when cancelling after a partial return', function () {
    $grill = Warehouse::factory()->create();
    $product = mergedStock(20, 10, $grill);
    $order = mergedOrder($product);
    app(OrderService::class)->completeOrder($order->id, ['cash' => 200]);
    app(OrderReturnService::class)->processReturn($order, [['order_item_id' => $order->items()->sole()->id, 'quantity' => 1, 'refund_amount' => 100]], 'Return', [['method' => 'cash', 'amount' => 100]], $order->shift_id);
    app(OrderService::class)->cancelOrder($order->id);
    expect(app(StockService::class)->getCurrentStock($product->id, $grill->id))->toBe(10.0)
        ->and((float) InventoryItemMovement::where('reason', 'order_return')->sum('quantity'))->toBe(2.0);
});

it('confirms stock issues using the locked documents current warehouse and lines', function () {
    $product = mergedStock();
    $grill = Warehouse::factory()->create();
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $grill->id, 'quantity' => 10]);
    $issue = StockIssue::factory()->create();
    $item = $issue->items()->create(['product_id' => $product->id, 'quantity' => 1]);
    $stale = $issue->fresh(['items']);
    $issue->update(['warehouse_id' => $grill->id]);
    $item->update(['quantity' => 3]);
    app(StockIssueService::class)->confirm($stale);
    expect(InventoryItemMovement::sole()->warehouse_id)->toBe($grill->id)
        ->and((float) InventoryItemMovement::sole()->quantity)->toBe(3.0)
        ->and(app(StockService::class)->getCurrentStock($product->id, $grill->id))->toBe(7.0);
});

it('deducts all fourteen pounds for one thousand units costing 0.014', function () {
    $product = mergedStock(0.014, 1000);
    $order = mergedOrder($product, 1000, 1000);
    app(OrderService::class)->completeOrder($order->id, ['cash' => 1000]);
    expect((float) $order->items()->sole()->cost)->toBe(0.014)
        ->and((float) $order->fresh()->profit)->toBe(986.0)
        ->and((float) InventoryItemMovement::sole()->total_cost)->toBe(14.0);
});

it('receives the full manufacturing pot despite rounded output unit costs', function (string $method) {
    app(SettingsService::class)->set('costing_method', $method);
    $input = mergedStock(100, 1);
    $output = Product::factory()->create(['type' => 'raw_material', 'cost' => 0.02]);
    InventoryItem::where('product_id', $output->id)->update(['quantity' => 1000]);
    $recipe = app(ManufacturingRecipeService::class)->create(['name' => 'Conservation', 'inputs' => [['product_id' => $input->id, 'quantity' => 1]], 'outputs' => [['product_id' => $output->id, 'is_main' => true, 'expected_quantity' => 3000]]]);
    $service = app(ManufacturingOrderService::class);
    $order = $service->createFromRecipe($recipe, Warehouse::default()->id, Warehouse::default()->id, 3000);
    $service->complete($order, [$order->outputs->sole()->id => 3000]);
    $movement = InventoryItemMovement::where('reason', MovementReason::MANUFACTURING_IN)->sole();
    expect((float) $movement->total_cost)->toBe(100.0)->and((float) $movement->unit_cost)->toBe(0.0333);
    if ($method === 'weighted_average') {
        expect((float) $output->fresh()->cost)->toBe(0.03);
    }
})->with(['off', 'immediate', 'weighted_average']);

it('uses saved item cost for historical profit margins rankings and order type summaries', function () {
    $product = mergedStock(23);
    $order = mergedOrder($product);
    app(OrderService::class)->completeOrder($order->id, ['cash' => 200]);
    $product->update(['cost' => 40]);
    $service = app(ProductsSalesReportService::class);
    $summary = $service->getPeriodSummary();
    expect($summary['total_profit'])->toBe(154.0)->and($summary['avg_profit_margin'])->toBe(77.0)
        ->and((float) $summary['most_profitable_product']->total_profit)->toBe(154.0)
        ->and((float) $summary['best_selling_product']->total_profit)->toBe(154.0)
        ->and(collect($service->getOrderTypePerformance())->sum('total_profit'))->toBe(154.0);
});

it('uses the open business date for movements and after midnight ledger and summary filters', function () {
    $product = mergedStock();
    Carbon::setTestNow('2026-11-11 01:00:00');
    $movement = app(StockService::class)->addStock([['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 30]], MovementReason::PURCHASE)->sole();
    expect(Schema::hasColumn('inventory_item_movements', 'business_date'))->toBeTrue();
    expect($movement->business_date->toDateString())->toBe('2026-11-10');
    $record = StockReportService::query('2026-11-10', '2026-11-10', true, productId: $product->id)->sole();
    expect((float) $record->incoming)->toBe(2.0)
        ->and((float) MovementSummaryReportService::rows('2026-11-10', '2026-11-10')['purchase']->quantity)->toBe(2.0)
        ->and(ItemLedgerReportService::rows($product->id, null, '2026-11-10', '2026-11-10'))->toHaveCount(1)
        ->and(ItemLedgerReportService::rows($product->id, null, '2026-11-11', '2026-11-11'))->toHaveCount(0)
        ->and(ItemLedgerReportService::openingBalance($product->id, null, '2026-11-11'))->toBe(2.0);
    $migration = require database_path('migrations/2026_11_10_340002_add_business_date_to_inventory_movements.php');
    $migration->down();
    $migration->up();
    expect($movement->fresh()->business_date->toDateString())->toBe('2026-11-11');
});

it('snapshots and refreshes stocktaking products in the selected warehouse', function () {
    $product = mergedStock(10, 30);
    $grill = Warehouse::factory()->create();
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $grill->id, 'quantity' => 4]);
    $page = livewire(CreateStocktaking::class)->set('data.warehouse_id', $grill->id)
        ->set('data.stocktaking_product_selector', $product->id);
    $items = array_values($page->get('data.items'));
    expect((float) $items[0]['stock_quantity'])->toBe(4.0);
    $page->set('data.warehouse_id', Warehouse::default()->id);
    expect((float) array_values($page->get('data.items'))[0]['stock_quantity'])->toBe(30.0);
    $page->set('data.items', [])->set('data.warehouse_id', $grill->id)
        ->callAction(\Filament\Actions\Testing\TestAction::make('importProducts')->schemaComponent('stocktakingImportActions', 'form'), ['selected_collection' => [$product->id], 'selected_products' => []]);
    expect((float) array_values($page->get('data.items'))[0]['stock_quantity'])->toBe(4.0);
});

it('keeps the thirty to 26.6667 order reversal history without a joined document', function () {
    $product = mergedStock(20);
    $order = mergedOrder($product, 5, 500);
    app(OrderService::class)->completeOrder($order->id, ['cash' => 500]);
    app(StockService::class)->addStock([['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 40]], MovementReason::PURCHASE);
    app(OrderService::class)->cancelOrder($order->id);
    $history = ProductCostHistory::where('source_type', Order::class)->sole();
    expect((float) $history->old_cost)->toBe(30.0)->and((float) $history->new_cost)->toBe(26.6667);
    $row = CostHistoryReportService::query('2026-11-10', '2026-11-10')->where('id', $history->id)->sole();
    expect($row->document_type)->toBeString()->not->toBeEmpty()->and($row->report_date)->toBe('2026-11-10');
});

it('exports the Grill running balance as three rather than the global thirteen', function () {
    $product = mergedStock();
    $grill = Warehouse::factory()->create();
    foreach ([[Warehouse::default()->id, 'in', 10], [$grill->id, 'in', 4], [$grill->id, 'out', 1]] as [$warehouseId, $operation, $quantity]) {
        InventoryItemMovement::create(['product_id' => $product->id, 'warehouse_id' => $warehouseId, 'operation' => $operation, 'reason' => $operation === 'in' ? 'purchase' : 'order', 'quantity' => $quantity, 'unit_cost' => 20, 'total_cost' => $quantity * 20]);
    }
    $widget = livewire(ItemLedgerReportTable::class, ['pageFilters' => ['warehouse_id' => $grill->id]]);
    $options = $widget->instance()->getTable()->getAction('export')->getOptions();
    $exporter = new ItemLedgerReportExporter(new Export(['exporter' => ItemLedgerReportExporter::class]), ['running_balance' => 'Balance'], $options);
    expect($exporter(InventoryItemMovement::orderByDesc('id')->first()))->toBe(['3']);
});

it('filters sales cards and order type summaries to category A only', function (bool $subCategory) {
    $main = Category::factory()->create();
    $category = $subCategory ? Category::factory()->create(['parent_id' => $main->id]) : $main;
    $product = mergedStock();
    $product->update(['category_id' => $category->id]);
    $a = mergedOrder($product, 1, 100);
    $a->update(['status' => OrderStatus::COMPLETED]);
    $other = mergedStock();
    $b = mergedOrder($other, 1, 200);
    $b->update(['status' => OrderStatus::COMPLETED]);
    $filters = ['mainCategoryId' => $main->id, 'subCategoryId' => $subCategory ? $category->id : null];
    $stats = livewire(ProductsSalesStatsWidget::class, ['pageFilters' => $filters])->instance();
    $summary = (new ReflectionMethod($stats, 'getPeriodSummary'))->invoke($stats);
    expect($summary['total_sales'])->toBe(100.0);
    $chart = livewire(OrderTypePerformanceWidget::class, ['pageFilters' => $filters])->instance();
    $types = (new ReflectionMethod($chart, 'getOrderTypePerformance'))->invoke($chart);
    expect(collect($types)->sum('total_sales'))->toBe(100.0);
})->with([false, true]);

it('summarises manual, adjustment and initial stock movements so the summary agrees with the ledger', function () {
    $product = mergedStock();
    $stock = app(StockService::class);
    $stock->addStock([['product_id' => $product->id, 'quantity' => 50]], MovementReason::INITIAL_STOCK);
    $stock->removeStock([['product_id' => $product->id, 'quantity' => 4]], MovementReason::ADJUSTMENT);
    $stock->addStock([['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 30]], MovementReason::PURCHASE);

    $today = app(\App\Services\InventoryDailyAggregationService::class)->currentBusinessDate()->toDateString();
    $rows = MovementSummaryReportService::rows($today, $today);
    $net = collect($rows)->sum(fn ($row) => ($row->operation === 'in' ? 1 : -1) * (float) $row->quantity);

    expect((float) $rows['other_in']->quantity)->toBe(50.0)
        ->and((float) $rows['other_out']->quantity)->toBe(4.0)
        ->and((float) $rows['purchase']->quantity)->toBe(10.0)
        ->and(round($net, 3))->toBe(56.0); // 50 − 4 + 10: every movement of the day is in some summary row
});
