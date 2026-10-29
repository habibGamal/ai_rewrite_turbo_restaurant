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
