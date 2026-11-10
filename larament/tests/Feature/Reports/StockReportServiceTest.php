<?php

use App\Enums\ProductType;
use App\Models\InventoryItem;
use App\Models\InventoryItemMovementDaily;
use App\Models\Product;
use App\Services\Reports\StockReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

it('resolves today preset to open day from yesterday and computes zero deviation when live matches ideal', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-29 01:00:00'));

    $product = Product::factory()->create([
        'type' => ProductType::Consumable,
        'cost' => 10,
    ]);

    InventoryItem::updateOrCreate(
        ['product_id' => $product->id],
        ['quantity' => 80],
    );

    $daily = openInventoryDay($product, Carbon::parse('2026-10-28'));
    $daily->update([
        'start_quantity' => 100,
        'sales_quantity' => 20,
        'return_sales_quantity' => 0,
        'incoming_quantity' => 0,
        'return_waste_quantity' => 0,
        'closed_at' => null,
    ]);

    $resolved = StockReportService::resolveDates('2026-10-29', '2026-10-29');

    expect($resolved['start'])->toBe('2026-10-28')
        ->and($resolved['end'])->toBe('2026-10-28')
        ->and($resolved['useLive'])->toBeTrue();

    $record = StockReportService::query($resolved['start'], $resolved['end'], $resolved['useLive'])
        ->where('products.id', $product->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and((float) $record->start_quantity)->toBe(100.0)
        ->and((float) $record->sales)->toBe(20.0)
        ->and((float) $record->actual_remaining_quantity)->toBe(80.0);

    $calc = StockReportService::calculate($record);

    expect($calc['ideal'])->toBe(80.0)
        ->and($calc['actual'])->toBe(80.0)
        ->and($calc['deviation'])->toBe(0.0)
        ->and($calc['deviation_value'])->toBe(0.0);
});

it('clamps future end date in this month preset to business date and uses live stock', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));

    $product = Product::factory()->create([
        'type' => ProductType::Consumable,
        'cost' => 20,
    ]);

    InventoryItem::updateOrCreate(
        ['product_id' => $product->id],
        ['quantity' => 45],
    );

    openInventoryDay($product, Carbon::parse('2026-10-15'));

    $resolved = StockReportService::resolveDates('2026-10-01', '2026-10-31');

    expect($resolved['start'])->toBe('2026-10-01')
        ->and($resolved['end'])->toBe('2026-10-15')
        ->and($resolved['useLive'])->toBeTrue();

    $record = StockReportService::query($resolved['start'], $resolved['end'], $resolved['useLive'])
        ->where('products.id', $product->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and((float) $record->actual_remaining_quantity)->toBe(45.0);
});

it('uses latest earlier closed row end quantity when past end date has no daily row', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-29 12:00:00'));

    $product = Product::factory()->create([
        'type' => ProductType::Consumable,
        'cost' => 15,
    ]);

    InventoryItem::updateOrCreate(
        ['product_id' => $product->id],
        ['quantity' => 99],
    );

    // Closed row on 2026-10-20
    InventoryItemMovementDaily::factory()->create([
        'product_id' => $product->id,
        'date' => '2026-10-20',
        'end_quantity' => 60,
        'closed_at' => Carbon::parse('2026-10-20 23:00:00'),
    ]);

    // Range queried for a holiday: 2026-10-21 to 2026-10-21 (no row exists for 2026-10-21)
    $resolved = StockReportService::resolveDates('2026-10-21', '2026-10-21');

    expect($resolved['useLive'])->toBeFalse();

    $record = StockReportService::query($resolved['start'], $resolved['end'], $resolved['useLive'])
        ->where('products.id', $product->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and((float) $record->actual_remaining_quantity)->toBe(60.0);
});

it('uses first row start quantity when product only has rows inside range', function () {
    $product = Product::factory()->create([
        'type' => ProductType::Consumable,
    ]);

    InventoryItem::updateOrCreate(
        ['product_id' => $product->id],
        ['quantity' => 50],
    );

    InventoryItemMovementDaily::factory()->create([
        'product_id' => $product->id,
        'date' => '2026-10-25',
        'start_quantity' => 50,
        'incoming_quantity' => 10,
        'end_quantity' => 60,
        'closed_at' => Carbon::parse('2026-10-25 22:00:00'),
    ]);

    $record = StockReportService::query('2026-10-25', '2026-10-26', false)
        ->where('products.id', $product->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and((float) $record->start_quantity)->toBe(50.0);
});

it('shows stocktaking difference as deviation with signed deviation value', function () {
    $record = (object) [
        'start_quantity' => 100,
        'incoming' => 0,
        'sales' => 20,
        'return_waste' => 0,
        'actual_remaining_quantity' => 75,
        'cost' => 15.00,
    ];

    $calc = StockReportService::calculate($record);

    expect($calc['ideal'])->toBe(80.0)
        ->and($calc['actual'])->toBe(75.0)
        ->and($calc['deviation'])->toBe(-5.0)
        ->and($calc['deviation_value'])->toBe(-75.00)
        ->and($calc['deviation_percentage'])->toBe(-6.3);
});

it('returns null deviation percentage when ideal is zero', function () {
    $record = (object) [
        'start_quantity' => 0,
        'incoming' => 0,
        'sales' => 0,
        'return_waste' => 0,
        'actual_remaining_quantity' => 5,
        'cost' => 10,
    ];

    $calc = StockReportService::calculate($record);

    expect($calc['ideal'])->toBe(0.0)
        ->and($calc['actual'])->toBe(5.0)
        ->and($calc['deviation'])->toBe(5.0)
        ->and($calc['deviation_value'])->toBe(50.00)
        ->and($calc['deviation_percentage'])->toBeNull();
});

it('gives the same numbers as before for a single-warehouse restaurant', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-09 14:00:00'));

    $main = \App\Models\Warehouse::default();
    $product = Product::factory()->create([
        'type' => ProductType::RawMaterial,
        'cost' => 25.00,
    ]);

    InventoryItem::updateOrCreate(
        ['product_id' => $product->id, 'warehouse_id' => $main->id],
        ['quantity' => 108],
    );

    InventoryItemMovementDaily::updateOrCreate(
        ['product_id' => $product->id, 'warehouse_id' => $main->id, 'date' => '2026-11-09'],
        [
            'start_quantity' => 100,
            'incoming_quantity' => 30,
            'sales_quantity' => 18,
            'return_sales_quantity' => 3,
            'return_waste_quantity' => 5,
            'end_quantity' => 108,
            'closed_at' => null,
        ],
    );

    $allRecord = StockReportService::query('2026-11-09', '2026-11-09', true)
        ->where('products.id', $product->id)
        ->first();

    $mainRecord = StockReportService::query('2026-11-09', '2026-11-09', true, $main->id)
        ->where('products.id', $product->id)
        ->first();

    foreach ([$allRecord, $mainRecord] as $record) {
        expect($record)->not->toBeNull()
            ->and((float) $record->start_quantity)->toBe(100.0)
            ->and((float) $record->incoming)->toBe(30.0)
            ->and((float) $record->sales)->toBe(15.0)
            ->and((float) $record->return_waste)->toBe(5.0)
            ->and((float) $record->actual_remaining_quantity)->toBe(108.0);

        $calc = StockReportService::calculate($record);

        expect($calc['total'])->toBe(130.0)
            ->and($calc['consumed'])->toBe(20.0)
            ->and($calc['ideal'])->toBe(110.0)
            ->and($calc['actual'])->toBe(108.0)
            ->and($calc['deviation'])->toBe(-2.0)
            ->and($calc['deviation_value'])->toBe(-50.00)
            ->and($calc['deviation_percentage'])->toBe(-1.8);
    }
});

it('shows Grill: start 2 + transfer in 10 − sales 9 = expected 3, counted 2.5 → deviation −0.5 × 85 = −42.50', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-09 18:00:00'));

    $main = \App\Models\Warehouse::default();
    $grill = \App\Models\Warehouse::factory()->create(['name' => 'مخزن الشوايا']);

    $marinatedChicken = Product::factory()->create([
        'name' => 'دجاج متبل',
        'type' => ProductType::RawMaterial,
        'cost' => 85.00,
    ]);

    InventoryItem::updateOrCreate(
        ['product_id' => $marinatedChicken->id, 'warehouse_id' => $main->id],
        ['quantity' => 20.0],
    );
    InventoryItem::updateOrCreate(
        ['product_id' => $marinatedChicken->id, 'warehouse_id' => $grill->id],
        ['quantity' => 2.5],
    );

    InventoryItemMovementDaily::create([
        'product_id' => $marinatedChicken->id,
        'warehouse_id' => $main->id,
        'date' => '2026-11-09',
        'start_quantity' => 30.0,
        'transfer_out_quantity' => 10.0,
        'end_quantity' => 20.0,
        'closed_at' => null,
    ]);

    InventoryItemMovementDaily::create([
        'product_id' => $marinatedChicken->id,
        'warehouse_id' => $grill->id,
        'date' => '2026-11-09',
        'start_quantity' => 2.0,
        'transfer_in_quantity' => 10.0,
        'sales_quantity' => 9.0,
        'end_quantity' => 2.5,
        'closed_at' => null,
    ]);

    $grillRecord = StockReportService::query('2026-11-09', '2026-11-09', true, $grill->id)
        ->where('products.id', $marinatedChicken->id)
        ->first();

    expect($grillRecord)->not->toBeNull()
        ->and((float) $grillRecord->start_quantity)->toBe(2.0)
        ->and((float) $grillRecord->transfer_in)->toBe(10.0)
        ->and((float) $grillRecord->sales)->toBe(9.0)
        ->and((float) $grillRecord->actual_remaining_quantity)->toBe(2.5);

    $calc = StockReportService::calculate($grillRecord);

    expect($calc['total'])->toBe(12.0)
        ->and($calc['consumed'])->toBe(9.0)
        ->and($calc['ideal'])->toBe(3.0)
        ->and($calc['actual'])->toBe(2.5)
        ->and($calc['deviation'])->toBe(-0.5)
        ->and($calc['deviation_value'])->toBe(-42.50);
});

it('cancels transfers out in the all-warehouses view', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-09 20:00:00'));

    $prep = \App\Models\Warehouse::default();
    $grill = \App\Models\Warehouse::factory()->create(['name' => 'مخزن الشوايا']);

    $product = Product::factory()->create([
        'type' => ProductType::RawMaterial,
        'cost' => 85.00,
    ]);

    InventoryItem::updateOrCreate(
        ['product_id' => $product->id, 'warehouse_id' => $prep->id],
        ['quantity' => 15.0],
    );
    InventoryItem::updateOrCreate(
        ['product_id' => $product->id, 'warehouse_id' => $grill->id],
        ['quantity' => 3.0],
    );

    InventoryItemMovementDaily::create([
        'product_id' => $product->id,
        'warehouse_id' => $prep->id,
        'date' => '2026-11-09',
        'start_quantity' => 10.0,
        'incoming_quantity' => 5.0,
        'manufacturing_in_quantity' => 18.0,
        'manufacturing_out_quantity' => 6.0,
        'transfer_out_quantity' => 10.0,
        'issue_quantity' => 2.0,
        'end_quantity' => 15.0,
        'closed_at' => null,
    ]);

    InventoryItemMovementDaily::create([
        'product_id' => $product->id,
        'warehouse_id' => $grill->id,
        'date' => '2026-11-09',
        'start_quantity' => 2.0,
        'transfer_in_quantity' => 10.0,
        'sales_quantity' => 9.0,
        'end_quantity' => 3.0,
        'closed_at' => null,
    ]);

    $rows = StockReportService::query('2026-11-09', '2026-11-09', true)
        ->where('products.id', $product->id)
        ->get();

    expect($rows)->toHaveCount(1);

    $record = $rows->first();
    expect((float) $record->start_quantity)->toBe(12.0)
        ->and((float) $record->incoming)->toBe(5.0)
        ->and((float) $record->transfer_in)->toBe(10.0)
        ->and((float) $record->transfer_out)->toBe(10.0)
        ->and((float) $record->manufacturing_in)->toBe(18.0)
        ->and((float) $record->manufacturing_out)->toBe(6.0)
        ->and((float) $record->issue)->toBe(2.0)
        ->and((float) $record->sales)->toBe(9.0)
        ->and((float) $record->actual_remaining_quantity)->toBe(18.0);

    $calc = StockReportService::calculate($record);

    // expected = start(12) + incoming(5) + transfer_in(10) + manufacturing_in(18)
    //          - sales(9) - return_waste(0) - transfer_out(10) - manufacturing_out(6) - issue(2) = 18
    expect($calc['ideal'])->toBe(18.0)
        ->and($calc['actual'])->toBe(18.0)
        ->and($calc['deviation'])->toBe(0.0)
        ->and($calc['deviation_value'])->toBe(0.0);
});

it('exposes the warehouse filter on the stock report page and new movement columns on the table', function () {
    $activeWarehouse = \App\Models\Warehouse::factory()->create(['name' => 'مخزن الشوايا', 'is_active' => true]);
    $inactiveWarehouse = \App\Models\Warehouse::factory()->create(['name' => 'مخزن قديم', 'is_active' => false]);

    $admin = \App\Models\User::factory()->admin()->create();
    \Pest\Laravel\actingAs($admin);

    $page = new \App\Filament\Pages\Reports\StockReport;
    $schema = $page->filtersForm(\Filament\Schemas\Schema::make($page));
    $select = collect($schema->getFlatComponents())->first(fn ($c) => $c instanceof \Filament\Forms\Components\Select && $c->getName() === 'warehouse_id');

    expect($select)->not->toBeNull()
        ->and($select->getPlaceholder())->toBe('كل المخازن')
        ->and($select->getOptions())->toHaveKey($activeWarehouse->id, 'مخزن الشوايا')
        ->and($select->getOptions())->not->toHaveKey($inactiveWarehouse->id);

    \Pest\Livewire\livewire(\App\Filament\Widgets\StockReportTable::class)
        ->assertTableColumnExists('transfer_in')
        ->assertTableColumnExists('transfer_out')
        ->assertTableColumnExists('manufacturing_in')
        ->assertTableColumnExists('manufacturing_out')
        ->assertTableColumnExists('issue');
});

it('sums start and historical end quantities per warehouse in the all-warehouses view', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-12 20:00:00'));

    $main = \App\Models\Warehouse::default();
    $grill = \App\Models\Warehouse::factory()->create(['name' => 'مخزن الشوايا']);
    $product = Product::factory()->create(['type' => ProductType::RawMaterial, 'cost' => 10]);

    // Main: two closed days before the range, then one day inside the range.
    foreach ([['2026-11-08', 20, 25], ['2026-11-09', 25, 30], ['2026-11-11', 30, 28]] as [$date, $startQty, $endQty]) {
        InventoryItemMovementDaily::create([
            'product_id' => $product->id, 'warehouse_id' => $main->id, 'date' => $date,
            'start_quantity' => $startQty, 'end_quantity' => $endQty, 'closed_at' => $date.' 23:00:00',
        ]);
    }
    // Grill: only rows before the range; the latest one (end 5) is the start for the range.
    foreach ([['2026-11-07', 0, 3], ['2026-11-09', 3, 5]] as [$date, $startQty, $endQty]) {
        InventoryItemMovementDaily::create([
            'product_id' => $product->id, 'warehouse_id' => $grill->id, 'date' => $date,
            'start_quantity' => $startQty, 'end_quantity' => $endQty, 'closed_at' => $date.' 23:00:00',
        ]);
    }

    $all = StockReportService::query('2026-11-10', '2026-11-11', false)->where('products.id', $product->id)->get();
    $grillOnly = StockReportService::query('2026-11-10', '2026-11-11', false, $grill->id)->where('products.id', $product->id)->first();

    expect($all)->toHaveCount(1)
        ->and((float) $all->first()->start_quantity)->toBe(35.0)          // Main 30 (first in range) + Grill 5 (last before)
        ->and((float) $all->first()->actual_remaining_quantity)->toBe(33.0) // Main 28 + Grill 5
        ->and((float) $grillOnly->start_quantity)->toBe(5.0)
        ->and((float) $grillOnly->actual_remaining_quantity)->toBe(5.0);
});
