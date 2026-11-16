<?php

use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;
use App\Filament\Pages\Reports\CostHistoryReport;
use App\Models\Product;
use App\Models\User;
use App\Modules\Manufacturing\Filament\Exports\ManufacturingYieldOrderExporter;
use App\Modules\Manufacturing\Filament\Pages\Reports\ManufacturingYieldReport;
use App\Modules\Manufacturing\Filament\Widgets\ManufacturingYieldOrderTable;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Livewire\livewire;

require_once __DIR__.'/../../../../Support/InventoryReportFixtures.php';

uses(RefreshDatabase::class);

/**
 * The manufacturing yield report lives in the Manufacturing module.
 */
function reportClass(string $kind, string $class): string
{
    $root = str_starts_with($class, 'ManufacturingYield') ? 'App\\Modules\\Manufacturing\\Filament\\' : 'App\\Filament\\';

    return $root.$kind.'\\'.$class;
}

dataset('document reports', ['ManufacturingYieldReport', 'CostHistoryReport']);

it('renders the report with shared filters and its table', function (string $name) {
    $this->actingAs(User::factory()->admin()->create());
    livewire(reportClass('Pages\\Reports', $name))
        ->assertSuccessful()->assertSeeLivewire(reportClass('Widgets', $name.'Table'))
        ->assertSchemaComponentExists('presetPeriod', 'filtersForm')->assertSchemaComponentExists('startDate', 'filtersForm')->assertSchemaComponentExists('endDate', 'filtersForm')
        ->assertSchemaComponentExists('warehouse_id', 'filtersForm')->assertSchemaComponentExists('main_category_id', 'filtersForm')
        ->assertSchemaComponentExists('sub_category_id', 'filtersForm')->assertSchemaComponentExists('product_id', 'filtersForm');
})->with('document reports');

it('requires the report permission for both page and table', function (string $name) {
    $this->actingAs(User::factory()->cashier()->create());
    livewire(reportClass('Pages\\Reports', $name))->assertForbidden();
    livewire(reportClass('Widgets', $name.'Table'))->assertForbidden();
})->with('document reports');

it('grants access with either the individual permission or view reports', function (string $name) {
    $page = reportClass('Pages\\Reports', $name);
    $user = User::factory()->create();
    $user->givePermissionTo($page::getReportPermission());
    $this->actingAs($user);
    expect($page::canAccess())->toBeTrue();
    livewire($page)->assertSuccessful();
    $user->syncPermissions(['view_reports']);
    expect($page::canAccess())->toBeTrue();
})->with('document reports');

it('provides an Excel export and the same columns in the table and exporter', function (string $name) {
    $this->actingAs(User::factory()->admin()->create());
    $exporter = reportClass('Exports', $name.'Exporter');
    $table = livewire(reportClass('Widgets', $name.'Table'))->assertSuccessful()->assertTableActionExists('export');
    foreach ($exporter::getColumns() as $column) {
        $table->assertTableColumnExists($column->getName());
    }
    expect($table->instance()->getTable()->getAction('export')->getFormats())->toBe([ExportFormat::Xlsx]);
})->with('document reports');

it('updates the date range from the same presets as the stock report', function () {
    $this->actingAs(User::factory()->admin()->create());
    livewire(CostHistoryReport::class)->fillForm(['presetPeriod' => 'last_7_days'], 'filtersForm')
        ->assertSchemaStateSet(['startDate' => now()->subDays(6)->toDateString(), 'endDate' => now()->toDateString()], 'filtersForm');
});

it('exports a complete filtered row after the queued query round trip', function (string $name) {
    $this->actingAs(User::factory()->admin()->create());
    $product = Product::factory()->create(['type' => 'raw_material']);
    openInventoryDay($product);
    reportFixture($name, $product);
    $exporterClass = reportClass('Exports', $name.'Exporter');
    $widget = livewire(reportClass('Widgets', $name.'Table'), ['pageFilters' => ['startDate' => '2026-11-01', 'endDate' => '2026-11-30']])->assertCountTableRecords(1);
    $query = $widget->instance()->getTableQueryForExport();
    $row = $query->first();
    $restored = EloquentSerializeFacade::unserialize(EloquentSerializeFacade::serialize($query))->find([$row->id])->sole();
    $columns = collect($exporterClass::getColumns())->mapWithKeys(fn ($column) => [$column->getName() => $column->getLabel()])->all();
    $exporter = new $exporterClass(new Export(['exporter' => $exporterClass]), $columns, []);
    $values = $exporter($restored);
    expect($values)->toHaveCount(count($columns))->toContain($product->name);
    if ($name === 'CostHistoryReport') {
        expect($values)->toContain('متوسط مرجح')->toContain('فاتورة مشتريات');
    }
})->with('document reports');

it('renders and exports the manufacturing input details with their output', function () {
    $this->actingAs(User::factory()->admin()->create());
    $product = Product::factory()->create(['type' => 'raw_material']);
    openInventoryDay($product);
    $raw = Product::factory()->create(['name' => 'دجاج خام', 'type' => 'raw_material']);
    $order = reportManufacturing($product, 100, 98, ['closed_at' => null]);
    $order->inputs()->create(['product_id' => $raw->id, 'planned_quantity' => 101, 'actual_quantity' => 102, 'unit_cost' => 10, 'total_cost' => 1020]);
    $order->update(['closed_at' => now()]);
    livewire(ManufacturingYieldReport::class)->assertSeeLivewire(ManufacturingYieldOrderTable::class);
    $widget = livewire(ManufacturingYieldOrderTable::class, ['pageFilters' => ['startDate' => '2026-11-01', 'endDate' => '2026-11-30']])
        ->assertCountTableRecords(1)->assertSee('دجاج خام')->assertTableActionExists('export');
    $query = $widget->instance()->getTableQueryForExport();
    $row = EloquentSerializeFacade::unserialize(EloquentSerializeFacade::serialize($query))->first();
    $exporter = new ManufacturingYieldOrderExporter(new Export(['exporter' => ManufacturingYieldOrderExporter::class]), ['inputs' => 'المدخلات'], []);
    expect($exporter($row)[0])->toContain('دجاج خام')->toContain('102.000')->toContain('1,020.00');
});
