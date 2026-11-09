<?php

use App\Enums\InventoryMovementOperation;
use App\Enums\MovementReason;
use App\Models\InventoryItem;
use App\Models\InventoryItemMovement;
use App\Models\InventoryItemMovementDaily;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('backfills existing stock movements and daily rows into the default warehouse and values old movements', function () {
    $migration = require database_path('migrations/2026_11_10_100002_add_warehouse_and_cost_to_inventory_ledger.php');
    $migration->down();
    $product = Product::withoutEvents(fn () => Product::factory()->create(['cost' => 20, 'product_ref' => 'MIGRATION-1']));
    DB::table('inventory_items')->insert(['product_id' => $product->id, 'quantity' => 3]);
    DB::table('inventory_item_movements')->insert([
        'product_id' => $product->id, 'quantity' => 3, 'operation' => 'in', 'reason' => 'purchase',
    ]);
    DB::table('inventory_item_movement_daily')->insert(['product_id' => $product->id, 'date' => now()->toDateString()]);
    $migration->up();

    foreach (['inventory_items', 'inventory_item_movements', 'inventory_item_movement_daily'] as $table) {
        expect(DB::table($table)->where('product_id', $product->id)->value('warehouse_id'))->toBe(Warehouse::default()->id);
    }
    $movement = InventoryItemMovement::where('product_id', $product->id)->first();
    expect((float) $movement->unit_cost)->toBe(20.0)
        ->and((float) $movement->total_cost)->toBe(60.0)
        ->and($movement->user_id)->toBeNull();

    $migration->down();
    expect(Schema::hasColumn('inventory_items', 'warehouse_id'))->toBeFalse()
        ->and(Schema::hasColumn('inventory_item_movements', 'unit_cost'))->toBeFalse()
        ->and(Schema::hasColumn('inventory_item_movement_daily', 'issue_quantity'))->toBeFalse();
    $migration->up();
});

it('allows the same product in two warehouses but not twice in one', function () {
    $product = Product::factory()->create();
    $warehouse = Warehouse::factory()->create();
    InventoryItem::updateOrCreate(['product_id' => $product->id, 'warehouse_id' => Warehouse::default()->id], ['quantity' => 3]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 5]);
    expect(InventoryItem::where('product_id', $product->id)->count())->toBe(2);
    expect(fn () => InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1]))
        ->toThrow(QueryException::class);
});

it('rolls warehouse balances back into the old unique keys without losing quantities', function () {
    $product = Product::factory()->create();
    $warehouse = Warehouse::factory()->create();
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => Warehouse::default()->id, 'quantity' => 3]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => -2]);
    foreach ([Warehouse::default()->id => 3, $warehouse->id => -2] as $warehouseId => $quantity) {
        InventoryItemMovementDaily::create([
            'product_id' => $product->id, 'warehouse_id' => $warehouseId, 'date' => now()->toDateString(),
            'start_quantity' => $quantity, 'end_quantity' => $quantity,
        ]);
    }
    $migration = require database_path('migrations/2026_11_10_100002_add_warehouse_and_cost_to_inventory_ledger.php');
    $migration->down();
    expect(DB::table('inventory_items')->where('product_id', $product->id)->count())->toBe(1)
        ->and((float) DB::table('inventory_items')->where('product_id', $product->id)->value('quantity'))->toBe(1.0)
        ->and((float) DB::table('inventory_item_movement_daily')->where('product_id', $product->id)->value('end_quantity'))->toBe(1.0);
    $migration->up();
});

it('allows daily rows per warehouse and rejects duplicate product warehouse dates', function () {
    $product = Product::factory()->create();
    $attributes = ['product_id' => $product->id, 'date' => now()->toDateString()];
    $first = InventoryItemMovementDaily::create($attributes + ['warehouse_id' => Warehouse::default()->id]);
    $other = InventoryItemMovementDaily::create($attributes + ['warehouse_id' => Warehouse::factory()->create()->id]);
    expect($first->warehouse->is_default)->toBeTrue()->and($other->warehouse->is_default)->toBeFalse();
    expect(fn () => InventoryItemMovementDaily::create($attributes + ['warehouse_id' => $other->warehouse_id]))->toThrow(QueryException::class);
});

it('refuses to delete a warehouse that has movements', function () {
    $warehouse = Warehouse::factory()->create();
    InventoryItemMovement::create([
        'product_id' => Product::factory()->create()->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 1,
        'operation' => InventoryMovementOperation::IN,
        'reason' => MovementReason::MANUAL,
        'unit_cost' => 20,
        'total_cost' => 20,
    ]);
    expect(fn () => $warehouse->delete())->toThrow(\Illuminate\Validation\ValidationException::class);
    $warehouse->update(['is_active' => false]);
    expect($warehouse->fresh()->is_active)->toBeFalse();
});
