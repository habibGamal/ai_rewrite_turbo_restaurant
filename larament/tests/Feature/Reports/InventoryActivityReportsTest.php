<?php

use App\Costing\CostingMethod;
use App\Models\Product;
use App\Models\ProductCostHistory;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Reports\Inventory\CostHistoryReportService;
use App\Services\Reports\Inventory\ManufacturingYieldReportService;
use App\Services\Reports\Inventory\StockIssuesReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/InventoryReportFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->product = Product::factory()->create(['type' => 'raw_material']);
    openInventoryDay($this->product);
});

it('totals staff meals in November at 4320 by destination warehouse and user', function () {
    $user = User::factory()->create();
    reportIssue($this->product, 2000, ['user_id' => $user->id]);
    reportIssue($this->product, 2320, ['user_id' => $user->id]);
    reportIssue($this->product, 999, ['user_id' => $user->id, 'closed_at' => null]);
    reportIssue($this->product, 999, ['user_id' => $user->id, 'business_date' => '2026-10-31']);
    $row = StockIssuesReportService::query('2026-11-01', '2026-11-30')->sole();
    expect((float) $row->value)->toBe(4320.0)->and($row->user_name)->toBe($user->name)
        ->and($row->destination)->toBe('وجبات العاملين');
});

it('separates issue destinations warehouses and users and filters the item costs', function () {
    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    reportIssue($this->product, 100, ['user_id' => $user->id]);
    reportIssue($this->product, 200, ['user_id' => $user->id, 'warehouse_id' => $warehouse->id]);
    reportIssue($this->product, 300, ['user_id' => $user->id, 'destination' => 'ضيافة']);
    reportIssue(Product::factory()->create(), 400, ['user_id' => $user->id]);
    expect(StockIssuesReportService::query('2026-11-01', '2026-11-30')->get())->toHaveCount(3);
    $row = StockIssuesReportService::query('2026-11-01', '2026-11-30', ['warehouse_id' => $warehouse->id, 'product_id' => $this->product->id])->sole();
    expect((float) $row->value)->toBe(200.0);
});

it('averages minus two three and four percent across manufacturing orders', function () {
    foreach ([98, 97, 96] as $actual) {
        reportManufacturing($this->product, 100, $actual);
    }
    reportManufacturing($this->product, 100, 50, ['closed_at' => null]);
    reportManufacturing($this->product, 100, 50, ['business_date' => '2026-10-31']);
    $row = ManufacturingYieldReportService::query('2026-11-01', '2026-11-30')->sole();
    expect((float) $row->expected_quantity)->toBe(300.0)->and((float) $row->actual_quantity)->toBe(291.0)
        ->and((float) $row->variance_quantity)->toBe(-9.0)->and((float) $row->variance_value)->toBe(-90.0)
        ->and((float) $row->variance_percentage)->toBe(-3.0)
        ->and((float) $row->average_variance_percentage)->toBe(-3.0);
});

it('distinguishes the arithmetic average from the volume weighted yield and filters the output warehouse', function () {
    $warehouse = Warehouse::factory()->create();
    reportManufacturing($this->product, 100, 98, ['destination_warehouse_id' => $warehouse->id]);
    reportManufacturing($this->product, 200, 192, ['destination_warehouse_id' => $warehouse->id]);
    reportManufacturing($this->product, 10, 1);
    $row = ManufacturingYieldReportService::query('2026-11-01', '2026-11-30', ['warehouse_id' => $warehouse->id])->sole();
    expect((float) $row->average_variance_percentage)->toBe(-3.0)
        ->and(round((float) $row->variance_percentage, 5))->toBe(-3.33333);
});

it('lists cost history 20 to 25 weighted average invoice 55 and user using the document business date', function () {
    $user = User::factory()->create();
    $invoice = reportPurchase($this->product, 10, 30, ['id' => 55]);
    $history = ProductCostHistory::create([
        'product_id' => $this->product->id, 'old_cost' => 20, 'new_cost' => 25, 'method' => 'weighted_average',
        'source_type' => $invoice->getMorphClass(), 'source_id' => $invoice->id, 'user_id' => $user->id,
    ]);
    $history->forceFill(['created_at' => '2026-12-01 01:00:00'])->save();
    $row = CostHistoryReportService::query('2026-11-01', '2026-11-30')->sole();
    expect((float) $row->old_cost)->toBe(20.0)->and((float) $row->new_cost)->toBe(25.0)
        ->and($row->method)->toBe(CostingMethod::WeightedAverage)
        ->and($row->document_type)->toBe('فاتورة مشتريات')->and((int) $row->document_id)->toBe(55)
        ->and($row->user_name)->toBe($user->name)->and($row->report_date)->toBe('2026-11-05');
});

it('resolves invoice item sources excludes drafts and includes manual cost edits by audit date', function () {
    $invoice = reportPurchase($this->product, 10, 30);
    $draft = reportPurchase($this->product, 10, 30, ['closed_at' => null]);
    foreach ([$invoice->items()->first(), $draft] as $source) {
        ProductCostHistory::create(['product_id' => $this->product->id, 'old_cost' => 20, 'new_cost' => 25, 'method' => 'immediate', 'source_type' => $source->getMorphClass(), 'source_id' => $source->id]);
    }
    $manual = ProductCostHistory::create(['product_id' => $this->product->id, 'old_cost' => 25, 'new_cost' => 26, 'method' => 'off']);
    $manual->forceFill(['created_at' => '2026-11-11 12:00:00'])->save();
    expect(CostHistoryReportService::query('2026-11-01', '2026-11-30')->get())->toHaveCount(2);
    $row = CostHistoryReportService::query('2026-11-01', '2026-11-30', ['warehouse_id' => $invoice->warehouse_id])->sole();
    expect((int) $row->document_id)->toBe($invoice->id);
});

it('exposes manufacturing order inputs and per output expected actual and variance', function () {
    $order = reportManufacturing($this->product, 100, 98, ['closed_at' => null]);
    $raw = Product::factory()->create(['type' => 'raw_material']);
    $order->inputs()->create(['product_id' => $raw->id, 'planned_quantity' => 101, 'actual_quantity' => 102, 'unit_cost' => 10, 'total_cost' => 1020]);
    $order->update(['closed_at' => now()]);
    $row = ManufacturingYieldReportService::detailsQuery('2026-11-01', '2026-11-30')->sole();
    expect((int) $row->document_id)->toBe($order->id)
        ->and((float) $row->expected_quantity)->toBe(100.0)->and((float) $row->actual_quantity)->toBe(98.0)
        ->and((float) $row->variance_percentage)->toBe(-2.0)
        ->and($row->order->inputs->sole()->product->id)->toBe($raw->id);
});

it('leaves undefined yield percentages empty when the expected quantity is zero', function () {
    reportManufacturing($this->product, 0, 0);
    $row = ManufacturingYieldReportService::query('2026-11-01', '2026-11-30')->sole();
    expect($row->variance_percentage)->toBeNull()->and($row->average_variance_percentage)->toBeNull();
});
