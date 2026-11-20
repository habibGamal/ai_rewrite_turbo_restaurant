<?php

use App\Filament\Pages\Reports\InventoryMovementsReport;
use App\Filament\Pages\Reports\ItemLedgerReport;
use App\Filament\Widgets\InventoryItemsTableWidget;
use App\Filament\Widgets\InventoryMovementsKpisWidget;
use App\Filament\Widgets\InventoryMovementTypesWidget;
use App\Filament\Widgets\InventoryValueBridgeWidget;
use App\Models\Category;
use App\Models\InventoryItemMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Shift;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Module;
use App\Services\FeatureService;
use App\Services\Reports\Inventory\InventoryMovementsReportService;
use App\Services\Reports\Inventory\InventoryReportFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

/**
 * A movement on a business date, valued at $value, optionally tied to a
 * document. Model events are skipped so stock levels stay untouched.
 */
function inventoryMove(Product $product, string $reason, string $operation, float $quantity, float $value, string $date, ?int $warehouseId = null, ?PurchaseInvoice $document = null): InventoryItemMovement
{
    return InventoryItemMovement::withoutEvents(fn () => InventoryItemMovement::create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouseId ?? Warehouse::default()->id,
        'business_date' => $date,
        'reason' => $reason,
        'operation' => $operation,
        'quantity' => $quantity,
        'unit_cost' => $quantity ? $value / $quantity : 0,
        'total_cost' => $value,
        'referenceable_type' => $document?->getMorphClass(),
        'referenceable_id' => $document?->id,
    ]));
}

function inventoryReport(): InventoryMovementsReportService
{
    return app(InventoryMovementsReportService::class);
}

function novemberFilters(array $extra = []): InventoryReportFilters
{
    return InventoryReportFilters::fromArray(['startDate' => '2026-11-01', 'endDate' => '2026-11-30', ...$extra]);
}

beforeEach(function () {
    // Rice at 20 a kilo: 10 before the period, then a month of movement.
    $this->rice = Product::factory()->create(['name' => 'أرز', 'type' => 'raw_material', 'unit' => 'kg', 'cost' => 20]);
    $this->invoice = PurchaseInvoice::factory()->create(['business_date' => '2026-11-03', 'closed_at' => now()]);
    inventoryMove($this->rice, 'purchase', 'in', 10, 200, '2026-10-20');
    inventoryMove($this->rice, 'purchase', 'in', 5, 100, '2026-11-03', document: $this->invoice);
    inventoryMove($this->rice, 'order', 'out', 4, 80, '2026-11-05');
    inventoryMove($this->rice, 'order_return', 'in', 1, 20, '2026-11-06');
    inventoryMove($this->rice, 'waste', 'out', 1, 20, '2026-11-07');
    inventoryMove($this->rice, 'stock_issue', 'out', 1, 20, '2026-11-08');
    inventoryMove($this->rice, 'stocktaking', 'out', 2, 40, '2026-11-09');
    inventoryMove($this->rice, 'stocktaking', 'in', 0.5, 10, '2026-11-10');
});

it('walks the stock value from 200 to 170 through every kind of movement', function () {
    $summary = inventoryReport()->summary(novemberFilters());

    expect($summary['opening'])->toBe(200.0)
        ->and($summary['closing'])->toBe(170.0)
        ->and($summary['net'])->toBe(-30.0)
        ->and($summary['purchases'])->toBe(100.0)
        ->and($summary['consumption'])->toBe(60.0)       // 80 sold − 20 returned
        ->and($summary['stocktaking_net'])->toBe(30.0)   // 40 short − 10 over
        ->and($summary['loss'])->toBe(50.0)              // 20 waste + 30 count
        ->and($summary['loss_rate'])->toBe(38.46);       // 50 ÷ (60 + 20 + 50)

    $bridge = inventoryReport()->bridge(novemberFilters());
    expect($bridge[0])->toMatchArray(['label' => 'أول المدة', 'to' => 200.0])
        ->and(end($bridge))->toMatchArray(['label' => 'آخر المدة', 'to' => 170.0]);

    // Each step starts where the last one ended.
    foreach (array_slice($bridge, 1, -1) as $i => $step) {
        expect($step['from'])->toBe($bridge[$i]['to']);
    }
});

it('counts documents and items per movement type and compares with the previous period', function () {
    inventoryMove($this->rice, 'waste', 'out', 1, 10, '2026-10-15');

    $rows = collect(inventoryReport()->typeRows(novemberFilters()))->keyBy('key');

    expect($rows['purchase'])->toMatchArray(['value' => 100.0, 'documents' => 1, 'items' => 1, 'direction' => 'in'])
        ->and($rows['waste']['change'])->toBe(100.0)   // 20 now against 10 in October
        ->and($rows['order']['share'])->toBe(50.0)     // 80 of the 160 going out
        ->and($rows->has('manufacturing_in'))->toBe(app(FeatureService::class)->moduleEnabled(Module::MANUFACTURING))
        ->and($rows->has('other_in'))->toBeFalse();
});

it('shows transfers only when one warehouse is chosen', function () {
    $bar = Warehouse::factory()->create(['name' => 'البار']);
    inventoryMove($this->rice, 'transfer_out', 'out', 2, 40, '2026-11-12');
    inventoryMove($this->rice, 'transfer_in', 'in', 2, 40, '2026-11-12', $bar->id);

    $all = collect(inventoryReport()->bridge(novemberFilters()))->pluck('key');
    $main = inventoryReport()->bridge(novemberFilters(['warehouse_id' => Warehouse::default()->id]));
    $barOnly = inventoryReport()->summary(novemberFilters(['warehouse_id' => $bar->id]));

    expect($all)->not->toContain('transfer')
        ->and(collect($main)->firstWhere('key', 'transfer'))->toMatchArray(['from' => 320.0, 'to' => 280.0])
        ->and(end($main)['to'])->toBe(130.0)
        ->and($barOnly['opening'])->toBe(0.0)
        ->and($barOnly['closing'])->toBe(40.0);
});

it('narrows by category and product and keeps the opening balance of the narrowed items', function () {
    $main = Category::factory()->create();
    $sub = Category::factory()->create(['parent_id' => $main->id]);
    $this->rice->update(['category_id' => $sub->id]);
    $oil = Product::factory()->create(['type' => 'raw_material']);
    inventoryMove($oil, 'purchase', 'in', 3, 300, '2026-11-04');

    expect(inventoryReport()->summary(novemberFilters(['main_category_id' => $main->id]))['closing'])->toBe(170.0)
        ->and(inventoryReport()->summary(novemberFilters(['sub_category_id' => $sub->id]))['purchases'])->toBe(100.0)
        ->and(inventoryReport()->summary(novemberFilters(['product_id' => $oil->id]))['purchases'])->toBe(300.0)
        ->and(inventoryReport()->summary(novemberFilters())['purchases'])->toBe(400.0);
});

it('gives each item its opening, flows, closing, loss rate and days of cover', function () {
    $idle = Product::factory()->create(['type' => 'raw_material']);
    inventoryMove($idle, 'purchase', 'in', 7, 70, '2026-09-01');
    Product::factory()->create(['type' => 'raw_material']);

    $items = inventoryReport()->itemsQuery(novemberFilters())->get()->keyBy('id');
    $rice = $items[$this->rice->id];

    expect($items)->toHaveCount(2)                                  // the product that never moved is left out
        ->and((float) $rice->opening_qty)->toBe(10.0)
        ->and((float) $rice->in_qty)->toBe(6.0)                     // purchase 5 + return 1
        ->and((float) $rice->out_qty)->toBe(5.0)                    // order 4 + issue 1
        ->and((float) $rice->waste_qty)->toBe(1.0)
        ->and((float) $rice->count_qty)->toBe(-1.5)
        ->and((float) $rice->closing_qty)->toBe(8.5)
        ->and((float) $rice->closing_value)->toBe(170.0)
        ->and(round((float) $rice->loss_rate, 2))->toBe(33.33)     // (1 + 1.5) ÷ (5 + 1 + 1.5)
        ->and(round((float) $rice->cover_days, 1))->toBe(63.8)     // 8.5 ÷ (4 used ÷ 30 days)
        ->and($items[$idle->id]->cover_days)->toBeNull();
});

it('values returned lines that did not go back to stock beside the bridge', function () {
    $shift = Shift::factory()->create();
    $order = Order::factory()->create(['shift_id' => $shift->id]);
    $item = OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $this->rice->id, 'quantity' => 3, 'cost' => 12.5]);
    $return = OrderReturn::withoutEvents(fn () => OrderReturn::create([
        'order_id' => $order->id, 'user_id' => User::factory()->create()->id, 'shift_id' => $shift->id, 'total_refund' => 60,
    ]));
    OrderReturnItem::withoutEvents(fn () => OrderReturnItem::create(['order_return_id' => $return->id, 'order_item_id' => $item->id, 'quantity' => 2, 'refund_amount' => 40, 'restocked' => false]));
    OrderReturnItem::withoutEvents(fn () => OrderReturnItem::create(['order_return_id' => $return->id, 'order_item_id' => $item->id, 'quantity' => 1, 'refund_amount' => 20, 'restocked' => true]));

    $period = InventoryReportFilters::fromArray(['startDate' => now()->toDateString(), 'endDate' => now()->toDateString()]);

    expect(inventoryReport()->damagedReturnsValue($period))->toBe(25.0)   // 2 damaged × 12.5; the restocked one is in the bridge already
        ->and(inventoryReport()->damagedReturnsValue($period->withPeriod($period->start->subDays(9), $period->start->subDay())))->toBe(0.0);
});

it('lists the documents behind a movement type', function () {
    $documents = inventoryReport()->documents(novemberFilters(), ['purchase']);
    $manual = inventoryReport()->documents(novemberFilters(), ['stocktaking_out', 'stocktaking_in']);

    expect($documents)->toHaveCount(1)
        ->and($documents[0])->toMatchArray(['number' => (string) $this->invoice->id, 'type' => 'فاتورة شراء', 'value' => 100.0, 'items' => 1])
        ->and($manual)->toHaveCount(2);
});

it('calls sales consumption by the business type', function () {
    $features = app(FeatureService::class);
    $features->enableModule(Module::RESTAURANT);
    expect(inventoryReport()->consumptionLabel())->toBe('استهلاك الطلبات');

    $features->setModules([Module::RETAIL]);
    expect(app(InventoryMovementsReportService::class)->consumptionLabel())->toBe('تكلفة البضاعة المباعة');
});

describe('the page', function () {
    beforeEach(fn () => $this->actingAs(User::factory()->admin()->create()));

    it('renders the overview and the items tab with their widgets', function () {
        $filters = ['startDate' => '2026-11-01', 'endDate' => '2026-11-30', 'presetPeriod' => 'custom'];

        livewire(InventoryMovementsReport::class, ['filters' => $filters])
            ->assertSuccessful()
            ->assertSeeLivewire(InventoryMovementsKpisWidget::class)
            ->assertSeeLivewire(InventoryValueBridgeWidget::class)
            ->assertSeeLivewire(InventoryMovementTypesWidget::class)
            ->assertDontSeeLivewire(InventoryItemsTableWidget::class)
            ->fillForm(['tab' => 'items'], 'filtersForm')
            // Switching the tab re-renders the page with the items widgets
            // (mounted children come back as stubs in a test re-render).
            ->assertSeeHtml('wire:name="App\\Filament\\Widgets\\InventoryItemsTableWidget"')
            ->assertDontSeeHtml('wire:name="App\\Filament\\Widgets\\InventoryValueBridgeWidget"');

        livewire(InventoryMovementsReport::class, ['filters' => [...$filters, 'tab' => 'items']])
            ->assertSeeLivewire(InventoryItemsTableWidget::class)
            ->assertDontSeeLivewire(InventoryValueBridgeWidget::class);

        livewire(InventoryMovementsKpisWidget::class, ['pageFilters' => $filters])->assertSee('170.00')->assertSee('38.5%');
        livewire(InventoryValueBridgeWidget::class, ['pageFilters' => $filters])->assertSee('أول المدة')->assertSee('مشتريات');
        livewire(InventoryItemsTableWidget::class, ['pageFilters' => $filters])
            ->assertCountTableRecords(1)
            ->assertSee('أرز');
    });

    it('opens the documents of a type from the table or the bridge', function () {
        $filters = ['startDate' => '2026-11-01', 'endDate' => '2026-11-30'];

        livewire(InventoryMovementTypesWidget::class, ['pageFilters' => $filters])
            ->assertDontSee('مستندات: مشتريات')
            ->call('select', 'purchase')
            ->assertSee('مستندات: مشتريات')
            ->assertSee('#'.$this->invoice->id)
            ->call('select', 'purchase')
            ->assertDontSee('مستندات: مشتريات');

        livewire(InventoryValueBridgeWidget::class, ['pageFilters' => $filters])
            ->call('select', 'stocktaking')
            ->assertDispatched('inventory-movement-type-selected', key: 'stocktaking');
    });

    it('links each item to its ledger for the same period', function () {
        $url = ItemLedgerReport::getUrl(['filters' => novemberFilters()->toLedgerFilters($this->rice->id)]);

        expect(urldecode($url))->toContain('filters[product_id]='.$this->rice->id)
            ->toContain('filters[startDate]=2026-11-01')
            ->toContain('filters[presetPeriod]=custom');
    });

    it('exports the movement types and the items to Excel', function () {
        livewire(InventoryMovementsReport::class, ['filters' => ['startDate' => '2026-11-01', 'endDate' => '2026-11-30']])
            ->callAction('exportInventoryMovements')
            ->assertFileDownloaded('inventory-movements-2026-11-01-2026-11-30.xlsx');
    });
});

it('replaces the six old reports and their permissions', function () {
    foreach (['purchases-report', 'waste-report', 'stocktaking-report', 'stock-issues-report', 'transfers-report', 'movement-summary-report'] as $path) {
        expect(collect(Route::getRoutes()->getRoutes())->contains(fn ($route) => str_ends_with($route->uri(), 'admin/'.$path)))->toBeFalse();
    }

    $this->actingAs(User::factory()->cashier()->create());
    livewire(InventoryMovementsReport::class)->assertForbidden();

    $user = User::factory()->create();
    $user->givePermissionTo('view_inventory_movements_report');
    $this->actingAs($user);
    expect(InventoryMovementsReport::canAccess())->toBeTrue();
});
