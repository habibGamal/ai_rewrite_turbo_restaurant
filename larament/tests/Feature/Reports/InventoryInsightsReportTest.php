<?php

use App\Filament\Pages\Reports\InventoryMovementsReport;
use App\Filament\Widgets\InventoryAlertsWidget;
use App\Filament\Widgets\InventoryLossesWidget;
use App\Filament\Widgets\InventoryMovementsKpisWidget;
use App\Filament\Widgets\InventoryPurchasesWidget;
use App\Filament\Widgets\InventoryValueBridgeWidget;
use App\Filament\Widgets\InventoryWarehousesWidget;
use App\Models\InventoryItemMovement;
use App\Models\Product;
use App\Models\ProductCostHistory;
use App\Models\PurchaseInvoice;
use App\Models\ReturnPurchaseInvoice;
use App\Models\Stocktaking;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Module;
use App\Services\FeatureService;
use App\Services\Reports\Inventory\InventoryInsightsReportService;
use App\Services\Reports\Inventory\InventoryReportFilters;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

/**
 * A movement on a business date, valued at $value, optionally tied to a
 * document and a user. Model events are skipped so stock stays untouched.
 */
function insightMove(Product $product, string $reason, string $operation, float $quantity, float $value, string $date, array $extra = [], ?Model $document = null): InventoryItemMovement
{
    return InventoryItemMovement::withoutEvents(fn () => InventoryItemMovement::create([
        'product_id' => $product->id,
        'warehouse_id' => Warehouse::default()->id,
        'business_date' => $date,
        'reason' => $reason,
        'operation' => $operation,
        'quantity' => $quantity,
        'unit_cost' => $quantity ? $value / $quantity : 0,
        'total_cost' => $value,
        'referenceable_type' => $document?->getMorphClass(),
        'referenceable_id' => $document?->id,
        ...$extra,
    ]));
}

function insights(): InventoryInsightsReportService
{
    return app(InventoryInsightsReportService::class);
}

function insightFilters(array $extra = []): InventoryReportFilters
{
    return InventoryReportFilters::fromArray(['startDate' => '2026-11-01', 'endDate' => '2026-11-30', ...$extra]);
}

beforeEach(function () {
    app(FeatureService::class)->setModules([Module::RESTAURANT]);

    $this->rice = Product::factory()->create(['name' => 'أرز', 'type' => 'raw_material', 'unit' => 'kg']);
    $this->oil = Product::factory()->create(['name' => 'زيت', 'type' => 'raw_material', 'unit' => 'liter']);
    $this->ahmed = Supplier::factory()->create(['name' => 'مورد أحمد']);
    $this->nour = Supplier::factory()->create(['name' => 'مورد نور']);
    $riceInvoice = PurchaseInvoice::factory()->create(['supplier_id' => $this->ahmed->id, 'business_date' => '2026-11-03', 'closed_at' => now()]);
    $oilInvoice = PurchaseInvoice::factory()->create(['supplier_id' => $this->nour->id, 'business_date' => '2026-11-04', 'closed_at' => now()]);
    $oilReturn = ReturnPurchaseInvoice::factory()->create(['supplier_id' => $this->nour->id]);
    $firstCount = Stocktaking::factory()->create();
    $secondCount = Stocktaking::factory()->create();

    insightMove($this->rice, 'purchase', 'in', 10, 200, '2026-10-20');
    insightMove($this->rice, 'purchase', 'in', 5, 100, '2026-11-03', document: $riceInvoice);
    insightMove($this->oil, 'purchase', 'in', 3, 300, '2026-11-04', document: $oilInvoice);
    insightMove($this->oil, 'purchase_return', 'out', 0.5, 50, '2026-11-05', document: $oilReturn);
    insightMove($this->rice, 'order', 'out', 4, 80, '2026-11-05');
    insightMove($this->rice, 'waste', 'out', 1, 20, '2026-11-07');
    insightMove($this->rice, 'stocktaking', 'out', 2, 40, '2026-11-09', document: $firstCount);
    insightMove($this->rice, 'stocktaking', 'in', 0.5, 10, '2026-11-10', document: $firstCount);
    insightMove($this->rice, 'stocktaking', 'out', 1, 20, '2026-11-20', document: $secondCount);
});

it('splits the loss into weeks starting on Saturday', function () {
    $trend = insights()->lossTrend(insightFilters());

    expect($trend)->toHaveCount(5)                          // 1–6, 7–13, 14–20, 21–27, 28–30
        ->and($trend[0]['label'])->toBe('01/11 – 06/11')
        ->and($trend[1])->toMatchArray(['waste' => 20.0, 'shortage' => 40.0, 'surplus' => 10.0, 'loss' => 50.0])
        ->and($trend[2]['loss'])->toBe(20.0)
        ->and(array_sum(array_column($trend, 'loss')))->toBe(70.0);
});

it('ranks the items that lost the most and compares the count with the recipes', function () {
    $items = insights()->topLossItems(insightFilters());

    expect($items)->toHaveCount(1)
        ->and($items[0])->toMatchArray([
            'name' => 'أرز',
            'waste_qty' => 1.0,
            'shortage_qty' => 2.5,          // 2 + 1 short − 0.5 over
            'loss' => 70.0,                 // 20 waste + 60 short − 10 over
            'rate' => 46.67,                // 70 ÷ (80 sold + 70)
            'against_theory' => 62.5,       // 50 net count ÷ 80 sold
            'share' => 100.0,
        ]);

    app(FeatureService::class)->setModules([Module::RETAIL]);
    expect(app(InventoryInsightsReportService::class)->topLossItems(insightFilters())[0]['against_theory'])->toBeNull();
});

it('finds items short in more than one count', function () {
    $repeated = insights()->repeatedShortages(insightFilters());

    expect($repeated)->toHaveCount(1)
        ->and($repeated[0])->toMatchArray(['name' => 'أرز', 'counts' => 2, 'quantity' => 3.0, 'value' => 60.0, 'last' => '2026-11-20'])
        ->and(insights()->repeatedShortages(insightFilters(['endDate' => '2026-11-15'])))->toBe([]);
});

it('lists stock that has not sold for thirty days', function () {
    $slow = collect(insights()->slowMoving(insightFilters()))->keyBy('name');

    expect($slow->keys()->all())->toBe(['زيت'])              // rice sold 25 days before the end
        ->and($slow['زيت'])->toMatchArray(['quantity' => 2.5, 'value' => 250.0, 'last_sale' => null]);

    $later = collect(insights()->slowMoving(insightFilters(['endDate' => '2026-12-10'])))->keyBy('name');
    expect($later['أرز'])->toMatchArray(['last_sale' => '2026-11-05', 'days' => 35]);
});

it('sums purchases and returns per supplier', function () {
    $suppliers = collect(insights()->suppliers(insightFilters()))->keyBy('supplier');

    expect($suppliers['مورد نور'])->toMatchArray(['invoices' => 1, 'purchases' => 300.0, 'returns' => 50.0, 'net' => 250.0, 'share' => 75.0])
        ->and($suppliers['مورد أحمد'])->toMatchArray(['invoices' => 1, 'purchases' => 100.0, 'returns' => 0.0, 'share' => 25.0])
        ->and($suppliers->keys()->first())->toBe('مورد نور');
});

it('shows cost changes and what they cost in the period purchases', function () {
    foreach ([[20, 22], [22, 25]] as [$old, $new]) {
        $history = ProductCostHistory::create(['product_id' => $this->rice->id, 'old_cost' => $old, 'new_cost' => $new, 'method' => 'weighted_average']);
        $history->forceFill(['created_at' => '2026-11-10 12:00:00'])->saveQuietly();
    }

    expect(insights()->costChanges(insightFilters()))->toBe([[
        'product_id' => $this->rice->id,
        'name' => 'أرز',
        'changes' => 2,
        'old_cost' => 20.0,
        'new_cost' => 25.0,
        'change' => 25.0,
        'purchased_qty' => 5.0,
        'impact' => 25.0,                  // 5 more a kilo × 5 kilos bought
    ]]);
});

it('compares warehouses and lists transfers and issues by destination', function () {
    $main = Warehouse::default();
    $bar = Warehouse::factory()->create(['name' => 'البار']);
    insightMove($this->rice, 'transfer_out', 'out', 2, 40, '2026-11-12');
    insightMove($this->rice, 'transfer_in', 'in', 2, 40, '2026-11-12', ['warehouse_id' => $bar->id]);

    $transfer = DB::table('stock_transfers')->insertGetId(['from_warehouse_id' => $main->id, 'to_warehouse_id' => $bar->id, 'closed_at' => now(), 'business_date' => '2026-11-12', 'total_cost' => 40, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('stock_transfer_items')->insert(['stock_transfer_id' => $transfer, 'product_id' => $this->rice->id, 'quantity' => 2, 'unit_cost' => 20, 'total_cost' => 40, 'created_at' => now(), 'updated_at' => now()]);
    foreach ([['المطبخ', 30], [' المطبخ ', 20], ['', 5], ['المطبخ', 99, null]] as $issue) {
        $id = DB::table('stock_issues')->insertGetId(['warehouse_id' => $main->id, 'destination' => $issue[0], 'closed_at' => array_key_exists(2, $issue) ? null : now(), 'business_date' => '2026-11-14', 'total_cost' => $issue[1], 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stock_issue_items')->insert(['stock_issue_id' => $id, 'product_id' => $this->rice->id, 'quantity' => 1, 'unit_cost' => $issue[1], 'total_cost' => $issue[1], 'created_at' => now(), 'updated_at' => now()]);
    }

    $warehouses = collect(insights()->warehouses(insightFilters(['warehouse_id' => $bar->id])))->keyBy('warehouse');

    expect($warehouses['البار'])->toMatchArray(['opening' => 0.0, 'transfer_in' => 40.0, 'closing' => 40.0])
        ->and($warehouses[$main->name])->toMatchArray(['opening' => 200.0, 'in' => 400.0, 'out' => 130.0, 'transfer_out' => 40.0, 'loss' => 70.0, 'closing' => 360.0])
        ->and(insights()->transferMatrix(insightFilters()))->toBe([['from' => $main->name, 'to' => 'البار', 'transfers' => 1, 'items' => 1, 'value' => 40.0]])
        ->and(insights()->issueDestinations(insightFilters()))->toBe([
            ['destination' => 'المطبخ', 'issues' => 2, 'value' => 50.0, 'share' => 90.9],
            ['destination' => 'بدون جهة', 'issues' => 1, 'value' => 5.0, 'share' => 9.1],
        ]);
});

it('raises alerts on stock data to look into', function () {
    $sugar = Product::factory()->create(['name' => 'سكر', 'type' => 'raw_material']);
    insightMove($sugar, 'order', 'out', 2, 0, '2026-11-11');                         // negative and zero cost
    insightMove($this->oil, 'adjustment', 'in', 1, 100, '2026-11-12');               // manual
    $count = Stocktaking::factory()->create(['closed_at' => now(), 'business_date' => '2026-11-09']);
    DB::table('stocktaking_items')->insert([
        ['stocktaking_id' => $count->id, 'product_id' => $this->rice->id, 'stock_quantity' => 10, 'real_quantity' => 7, 'price' => 20, 'total' => 0, 'created_at' => now(), 'updated_at' => now()],
        ['stocktaking_id' => $count->id, 'product_id' => $this->oil->id, 'stock_quantity' => 10, 'real_quantity' => 9.5, 'price' => 100, 'total' => 0, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $sara = User::factory()->create(['name' => 'سارة']);
    $omar = User::factory()->create(['name' => 'عمر']);
    foreach ([30, 30, 30] as $value) {
        insightMove($this->oil, 'waste', 'out', 0.1, $value, '2026-11-15', ['user_id' => $sara->id]);
    }
    insightMove($this->oil, 'waste', 'out', 0.1, 5, '2026-11-15', ['user_id' => $omar->id]);

    $alerts = collect(insights()->alerts(insightFilters()))->keyBy('key');

    expect($alerts['negative'])->toMatchArray(['count' => 1, 'level' => 'danger'])
        ->and($alerts['negative']['rows'][0]['الصنف'])->toBe('سكر')
        ->and($alerts['zero_cost']['count'])->toBe(1)
        ->and($alerts['manual'])->toMatchArray(['count' => 1, 'level' => 'warning'])
        ->and($alerts['variance']['count'])->toBe(1)                                // 30% off; oil is only 5%
        ->and($alerts['variance']['rows'][0])->toMatchArray(['الصنف' => 'أرز', 'القيمة' => '60.00'])
        ->and($alerts['waste_user']['count'])->toBe(1)                              // سارة: 90 of 115
        ->and($alerts['waste_user']['rows'][0]['المستخدم'])->toBe('سارة');

    $quiet = collect(insights()->alerts(insightFilters(['startDate' => '2026-10-01', 'endDate' => '2026-10-31'])))->keyBy('key');
    expect($quiet->pluck('level')->unique()->all())->toBe(['success']);
});

describe('the page', function () {
    beforeEach(fn () => $this->actingAs(User::factory()->admin()->create()));

    it('shows each tab with its widget', function (string $tab, string $widget, bool $kpis) {
        $page = livewire(InventoryMovementsReport::class, ['filters' => ['startDate' => '2026-11-01', 'endDate' => '2026-11-30', 'tab' => $tab]])
            ->assertSuccessful()
            ->assertSeeLivewire($widget)
            ->assertDontSeeLivewire(InventoryValueBridgeWidget::class);

        $kpis ? $page->assertSeeLivewire(InventoryMovementsKpisWidget::class) : $page->assertDontSeeLivewire(InventoryMovementsKpisWidget::class);
    })->with([
        'losses' => ['losses', InventoryLossesWidget::class, true],
        'purchases' => ['purchases', InventoryPurchasesWidget::class, true],
        'warehouses' => ['warehouses', InventoryWarehousesWidget::class, true],
        'alerts' => ['alerts', InventoryAlertsWidget::class, false],
    ]);

    it('renders the content of each tab', function () {
        $filters = ['startDate' => '2026-11-01', 'endDate' => '2026-11-30'];

        livewire(InventoryLossesWidget::class, ['pageFilters' => $filters])
            ->assertSee('أكتر الأصناف فاقدًا')->assertSee('أرز')->assertSee('62.5%')->assertSee('عجز متكرر')
            ->assertDontSee('بضاعة راكدة');
        livewire(InventoryPurchasesWidget::class, ['pageFilters' => $filters])
            ->assertSee('مورد نور')->assertSee('250.00')->assertSee('سجل تغيرات التكلفة');
        livewire(InventoryWarehousesWidget::class, ['pageFilters' => $filters])
            ->assertSee(Warehouse::default()->name)->assertSee('مفيش تحويلات');
        livewire(InventoryAlertsWidget::class, ['pageFilters' => $filters])
            ->assertSee('أرصدة بالسالب')->assertSee('فروق جرد كبيرة')->assertSee('تمام');

        app(FeatureService::class)->setModules([Module::RETAIL]);
        livewire(InventoryLossesWidget::class, ['pageFilters' => $filters])->assertSee('بضاعة راكدة')->assertSee('زيت');
    });
});
