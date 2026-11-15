<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Reports\Inventory\PurchasesReportService;
use App\Services\Reports\Inventory\StocktakingReportService;
use App\Services\Reports\Inventory\WasteReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/InventoryReportFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->product = Product::factory()->create(['type' => 'raw_material', 'cost' => 999]);
    openInventoryDay($this->product);
});

it('totals two closed purchases at their posted prices with a quantity weighted average', function () {
    reportPurchase($this->product, 10, 20);
    reportPurchase($this->product, 10, 30);
    reportPurchase($this->product, 100, 99, ['closed_at' => null]);
    reportPurchase($this->product, 100, 99, ['business_date' => '2026-10-31']);

    $row = PurchasesReportService::query('2026-11-01', '2026-11-30')->sole();
    expect((float) $row->quantity)->toBe(20.0)
        ->and((float) $row->value)->toBe(500.0)
        ->and((float) $row->average_price)->toBe(25.0);
});

it('separates suppliers and warehouses and filters main category subcategory and product', function () {
    $main = Category::factory()->create();
    $sub = Category::factory()->create(['parent_id' => $main->id]);
    $this->product->update(['category_id' => $sub->id]);
    $warehouse = Warehouse::factory()->create();
    $supplier = Supplier::factory()->create();
    reportPurchase($this->product, 10, 20);
    reportPurchase($this->product, 10, 30, ['warehouse_id' => $warehouse->id, 'supplier_id' => $supplier->id]);
    reportPurchase(Product::factory()->create(), 5, 90);
    $filters = ['warehouse_id' => $warehouse->id, 'main_category_id' => $main->id, 'sub_category_id' => $sub->id, 'product_id' => $this->product->id];
    $row = PurchasesReportService::query('2026-11-01', '2026-11-30', $filters)->sole();
    expect((float) $row->value)->toBe(300.0)->and($row->supplier_name)->toBe($supplier->name);
    $filters['main_category_id'] = Category::factory()->create()->id;
    expect(PurchasesReportService::query('2026-11-01', '2026-11-30', $filters)->count())->toBe(0);
});

it('values waste from two documents using movement costs rather than item totals or current cost', function () {
    reportWaste($this->product, 2, 40);
    reportWaste($this->product, 3, 90);
    reportWaste($this->product, 99, 999, ['closed_at' => null]);
    reportWaste($this->product, 99, 999, ['business_date' => '2026-12-01']);
    $row = WasteReportService::query('2026-11-01', '2026-11-30')->sole();
    expect((float) $row->quantity)->toBe(5.0)->and((float) $row->value)->toBe(130.0);
});

it('keeps each closed count with expected actual and signed movement quantity and value', function () {
    $first = reportCount($this->product, 10, 8, -40);
    $second = reportCount($this->product, 8, 11, 90);
    reportCount($this->product, 11, 0, -999, ['closed_at' => null]);
    reportCount($this->product, 11, 0, -999, ['business_date' => '2026-10-31']);
    $rows = StocktakingReportService::query('2026-11-01', '2026-11-30')->orderBy('document_id')->get();
    expect($rows)->toHaveCount(2)
        ->and((int) $rows[0]->document_id)->toBe($first->id)
        ->and((float) $rows[0]->expected_quantity)->toBe(10.0)
        ->and((float) $rows[0]->actual_quantity)->toBe(8.0)
        ->and((float) $rows[0]->variance_quantity)->toBe(-2.0)
        ->and((float) $rows[0]->variance_value)->toBe(-40.0)
        ->and((int) $rows[1]->document_id)->toBe($second->id)
        ->and((float) $rows[1]->variance_quantity)->toBe(3.0)
        ->and((float) $rows[1]->variance_value)->toBe(90.0);
});

it('includes unchanged counts and uses confirmation movements when the count snapshot is stale', function () {
    $unchanged = reportCount($this->product, 10, 10, 0);
    $stale = reportCount($this->product, 10, 8, -60);
    $stale->items()->update(['stock_quantity' => 9]);
    $rows = StocktakingReportService::query('2026-11-01', '2026-11-30')->get()->keyBy('document_id');
    expect((float) $rows[$unchanged->id]->variance_value)->toBe(0.0)
        ->and((float) $rows[$stale->id]->expected_quantity)->toBe(10.0)
        ->and((float) $rows[$stale->id]->variance_quantity)->toBe(-2.0);
});

it('handles free purchases without dividing by zero', function () {
    reportPurchase($this->product, 10, 0);
    expect((float) PurchasesReportService::query('2026-11-01', '2026-11-30')->sole()->average_price)->toBe(0.0);
});

dataset('inventory service reports', ['PurchasesReport', 'WasteReport', 'StocktakingReport', 'StockIssuesReport', 'ManufacturingYieldReport', 'CostHistoryReport']);

it('applies every shared filter and includes main category products as well as children', function (string $report) {
    $service = ($report === 'ManufacturingYieldReport' ? 'App\\Modules\\Manufacturing\\Services\\' : 'App\\Services\\Reports\\Inventory\\').$report.'Service';
    $main = Category::factory()->create();
    $sub = Category::factory()->create(['parent_id' => $main->id]);
    $this->product->update(['category_id' => $sub->id]);
    $warehouse = Warehouse::factory()->create(['is_active' => false]);
    reportFixture($report, $this->product, ['warehouse_id' => $warehouse->id]);
    reportFixture($report, $this->product);
    reportFixture($report, Product::factory()->create(['category_id' => $main->id]));
    $filters = ['main_category_id' => $main->id, 'sub_category_id' => $sub->id, 'product_id' => $this->product->id, 'warehouse_id' => $warehouse->id];
    expect($service::query('2026-11-01', '2026-11-30', $filters)->get())->toHaveCount(1);
    foreach (['main_category_id' => Category::factory()->create()->id, 'sub_category_id' => Category::factory()->create()->id, 'product_id' => Product::factory()->create()->id, 'warehouse_id' => Warehouse::factory()->create()->id] as $key => $id) {
        expect($service::query('2026-11-01', '2026-11-30', array_replace($filters, [$key => $id]))->count())->toBe(0);
    }
    expect($service::query('2026-11-01', '2026-11-30', ['main_category_id' => $main->id])->count())->toBeGreaterThan(1);
})->with('inventory service reports');

it('includes the last business date regardless of the posting timestamp', function (string $report) {
    $service = ($report === 'ManufacturingYieldReport' ? 'App\\Modules\\Manufacturing\\Services\\' : 'App\\Services\\Reports\\Inventory\\').$report.'Service';
    reportFixture($report, $this->product, ['business_date' => '2026-11-30']);
    reportFixture($report, $this->product, ['business_date' => '2026-12-01']);
    expect($service::query('2026-11-30', '2026-11-30')->get())->toHaveCount(1);
})->with('inventory service reports');
