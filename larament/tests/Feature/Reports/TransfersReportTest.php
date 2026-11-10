<?php

use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;
use App\Filament\Exports\TransfersReportExporter;
use App\Filament\Pages\Reports\TransfersReport;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Filament\Widgets\TransfersReportTable;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Reports\Inventory\ItemLedgerReportService;
use App\Services\Reports\Inventory\TransfersReportService;
use App\Services\StockTransferService;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(\Carbon\Carbon::parse('2026-11-10 12:00:00'));
    $this->main = Category::factory()->create();
    $this->sub = Category::factory()->create(['parent_id' => $this->main->id]);
    $this->product = Product::factory()->create(['name' => 'دجاج متبل', 'type' => 'raw_material', 'cost' => 85, 'category_id' => $this->sub->id]);
    $this->prep = Warehouse::factory()->create(['name' => 'التجهيز']);
    $this->grill = Warehouse::factory()->create(['name' => 'الشواية']);
    openInventoryDay($this->product);
});

function reportTransfer(Product $product, Warehouse $from, Warehouse $to, float $qty = 10): StockTransfer
{
    $transfer = StockTransfer::factory()->create(['from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id]);
    $transfer->items()->create(['product_id' => $product->id, 'quantity' => $qty]);
    app(StockTransferService::class)->confirm($transfer);

    return $transfer;
}

it('transfers report shows from to quantity and historical movement value for confirmed documents', function () {
    reportTransfer($this->product, $this->prep, $this->grill);
    reportTransfer($this->product, $this->prep, $this->grill, 2);
    StockTransfer::factory()->create(['from_warehouse_id' => $this->prep->id, 'to_warehouse_id' => $this->grill->id]);
    $old = reportTransfer($this->product, $this->prep, $this->grill, 100);
    DB::table('stock_transfers')->where('id', $old->id)->update(['business_date' => '2026-10-31']);
    $this->product->update(['cost' => 120]);
    $row = TransfersReportService::query('2026-11-01', '2026-11-30')->sole();
    expect($row->from_warehouse_name)->toBe('التجهيز')->and($row->to_warehouse_name)->toBe('الشواية')
        ->and($row->product_name)->toBe('دجاج متبل')
        ->and((float) $row->quantity)->toBe(12.0)->and((float) $row->value)->toBe(1020.0);
});

it('filters by either warehouse and main sub category and product without mixing warehouse pairs', function () {
    reportTransfer($this->product, $this->prep, $this->grill);
    reportTransfer($this->product, $this->grill, $this->prep, 3);
    $other = Product::factory()->create(['type' => 'consumable']);
    reportTransfer($other, $this->prep, $this->grill, 1);
    foreach ([$this->prep->id, $this->grill->id] as $id) {
        $rows = TransfersReportService::query('2026-11-01', '2026-11-30', ['warehouse_id' => $id, 'main_category_id' => $this->main->id, 'sub_category_id' => $this->sub->id, 'product_id' => $this->product->id])->get();
        expect($rows)->toHaveCount(2)->and((float) $rows->sum('quantity'))->toBe(13.0);
    }
    foreach ([['warehouse_id' => Warehouse::factory()->create()->id], ['main_category_id' => Category::factory()->create()->id], ['sub_category_id' => $this->main->id], ['product_id' => 999999]] as $filters) {
        expect(TransfersReportService::query('2026-11-01', '2026-11-30', $filters)->count())->toBe(0);
    }
});

it('renders the report filters table and queued Excel export with complete grouped values', function () {
    $this->actingAs($user = User::factory()->admin()->create());
    reportTransfer($this->product, $this->prep, $this->grill);
    reportTransfer($this->product, $this->prep, $this->grill, 2);
    $page = livewire(TransfersReport::class)->assertSuccessful()->assertSeeLivewire(TransfersReportTable::class);
    foreach (['startDate', 'endDate', 'warehouse_id', 'main_category_id', 'sub_category_id', 'product_id'] as $field) {
        $page->assertSchemaComponentExists($field, 'filtersForm');
    }
    $widget = livewire(TransfersReportTable::class, ['pageFilters' => ['startDate' => '2026-11-01', 'endDate' => '2026-11-30']])
        ->assertCountTableRecords(1)->assertSee('التجهيز')->assertSee('الشواية')->assertSee('دجاج متبل')->assertTableActionExists('export');
    expect($widget->instance()->getTable()->getAction('export')->getFormats())->toBe([ExportFormat::Xlsx]);
    $query = $widget->instance()->getTableQueryForExport();
    $row = $query->first();
    $restored = EloquentSerializeFacade::unserialize(EloquentSerializeFacade::serialize($query))->find([$row->id])->sole();
    $columns = collect(TransfersReportExporter::getColumns())->mapWithKeys(fn ($column) => [$column->getName() => $column->getLabel()])->all();
    $export = new Export(['exporter' => TransfersReportExporter::class]);
    $export->user()->associate($user);
    $exporter = new TransfersReportExporter($export, $columns, []);
    expect($exporter($restored))->toBe(['التجهيز', 'الشواية', 'دجاج متبل', '12', '1020']);
});

it('requires the transfers report permission for the page and table', function () {
    $user = User::factory()->cashier()->create();
    $this->actingAs($user);
    livewire(TransfersReport::class)->assertForbidden();
    livewire(TransfersReportTable::class)->assertForbidden();
    $user->givePermissionTo('view_transfers_report');
    livewire(TransfersReport::class)->assertSuccessful();
});

it('resolves the item ledger transfer label and link to the new resource', function () {
    $this->actingAs(User::factory()->admin()->create());
    $transfer = reportTransfer($this->product, $this->prep, $this->grill);
    $row = ItemLedgerReportService::rows($this->product->id, $this->prep->id, '2026-11-01', '2026-11-30')->sole();
    expect($row->document_type)->toBe('إذن تحويل')
        ->and($row->document_url)->toBe(StockTransferResource::getUrl('view', ['record' => $transfer]));
    $this->get($row->document_url)->assertOk();
});
