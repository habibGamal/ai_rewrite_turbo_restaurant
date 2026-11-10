<?php

use App\Models\InventoryItemMovementDaily;
use App\Models\Product;
use App\Sync\BuilderRegistry;
use App\Sync\Builders\InventoryDayBuilder;
use App\Sync\DocumentType;

it('builds an inventory_day snapshot for a closed date', function () {
    $product1 = Product::factory()->create();
    $product2 = Product::factory()->create();

    InventoryItemMovementDaily::create([
        'product_id' => $product1->id,
        'date' => '2026-10-29',
        'start_quantity' => 10.000,
        'incoming_quantity' => 5.000,
        'sales_quantity' => 3.000,
        'return_sales_quantity' => 1.000,
        'return_waste_quantity' => 0.500,
        'end_quantity' => 12.500,
        'closed_at' => '2026-10-29 23:59:00',
    ]);

    InventoryItemMovementDaily::create([
        'product_id' => $product2->id,
        'date' => '2026-10-29',
        'start_quantity' => 20.000,
        'incoming_quantity' => 0.000,
        'sales_quantity' => 4.000,
        'return_sales_quantity' => 0.000,
        'return_waste_quantity' => 1.000,
        'end_quantity' => 15.000,
        'closed_at' => '2026-10-29 23:59:00',
    ]);

    // Another date to test isolation
    InventoryItemMovementDaily::create([
        'product_id' => $product1->id,
        'date' => '2026-10-30',
        'start_quantity' => 12.500,
        'incoming_quantity' => 0,
        'sales_quantity' => 0,
        'return_sales_quantity' => 0,
        'return_waste_quantity' => 0,
        'end_quantity' => 12.500,
    ]);

    $builder = app(InventoryDayBuilder::class);
    $data = $builder->withKey('2026-10-29')->build('dummy-uuid');

    expect($data)->not->toBeNull()
        ->and($data['date'])->toBe('2026-10-29')
        ->and($data['rows'])->toHaveCount(2)
        ->and($data['rows'][0])->toBe([
            'product_uuid' => $product1->uuid,
            'start_qty' => '10.000',
            'incoming_qty' => '5.000',
            'sales_qty' => '3.000',
            'return_sales_qty' => '1.000',
            'waste_qty' => '0.500',
            'end_qty' => '12.500',
        ])
        ->and($data['rows'][1])->toBe([
            'product_uuid' => $product2->uuid,
            'start_qty' => '20.000',
            'incoming_qty' => '0.000',
            'sales_qty' => '4.000',
            'return_sales_qty' => '0.000',
            'waste_qty' => '1.000',
            'end_qty' => '15.000',
        ]);
});

it('returns null when key is null or no data exists for the date', function () {
    $builder = app(InventoryDayBuilder::class);

    // No key
    expect($builder->build('dummy-uuid'))->toBeNull();

    // Key with no data
    expect($builder->withKey('2099-01-01')->build('dummy-uuid'))->toBeNull();
});

it('is registered in BuilderRegistry and returns InventoryDay type', function () {
    $builder = BuilderRegistry::for(DocumentType::InventoryDay);

    expect($builder)->toBeInstanceOf(InventoryDayBuilder::class)
        ->and($builder->type())->toBe(DocumentType::InventoryDay);
});

it('keeps sending the old payload shape (one row per product, summed across warehouses) until the platform PR lands', function () {
    $main = \App\Models\Warehouse::default();
    $grill = \App\Models\Warehouse::factory()->create(['name' => 'مخزن الشوايا']);

    $product = Product::factory()->create();

    InventoryItemMovementDaily::create([
        'product_id' => $product->id,
        'warehouse_id' => $main->id,
        'date' => '2026-11-09',
        'start_quantity' => 30.000,
        'incoming_quantity' => 20.000,
        'sales_quantity' => 0.000,
        'return_sales_quantity' => 0.000,
        'return_waste_quantity' => 1.000,
        'transfer_in_quantity' => 0.000,
        'transfer_out_quantity' => 10.000,
        'manufacturing_in_quantity' => 0.000,
        'manufacturing_out_quantity' => 5.000,
        'issue_quantity' => 2.000,
        'end_quantity' => 32.000,
        'closed_at' => '2026-11-09 23:59:00',
    ]);

    InventoryItemMovementDaily::create([
        'product_id' => $product->id,
        'warehouse_id' => $grill->id,
        'date' => '2026-11-09',
        'start_quantity' => 2.000,
        'incoming_quantity' => 0.000,
        'sales_quantity' => 9.000,
        'return_sales_quantity' => 1.000,
        'return_waste_quantity' => 0.500,
        'transfer_in_quantity' => 10.000,
        'transfer_out_quantity' => 0.000,
        'manufacturing_in_quantity' => 0.000,
        'manufacturing_out_quantity' => 0.000,
        'issue_quantity' => 0.000,
        'end_quantity' => 3.500,
        'closed_at' => '2026-11-09 23:59:00',
    ]);

    $builder = app(InventoryDayBuilder::class);
    $data = $builder->withKey('2026-11-09')->build('dummy-uuid');

    expect($data)->not->toBeNull()
        ->and($data['date'])->toBe('2026-11-09')
        ->and($data['rows'])->toHaveCount(1)
        ->and($data['rows'][0])->toBe([
            'product_uuid' => $product->uuid,
            'start_qty' => '32.000',
            'incoming_qty' => '20.000',
            'sales_qty' => '9.000',
            'return_sales_qty' => '1.000',
            'waste_qty' => '1.500',
            'end_qty' => '35.500',
        ]);
});
