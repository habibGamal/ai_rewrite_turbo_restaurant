<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/InventoryReportFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->product = Product::factory()->create(['type' => 'raw_material', 'cost' => 999]);
    openInventoryDay($this->product);
});

dataset('inventory service reports', ['ManufacturingYieldReport', 'CostHistoryReport']);

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
