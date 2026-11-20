<?php

use App\Enums\ProductType;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;

it('creates the default main warehouse on migrate', function () {
    expect(Warehouse::default()->name)->toBe('المخزن الرئيسي')
        ->and(Warehouse::where('is_default', true)->count())->toBe(1);
});

it('refuses to delete or deactivate the default warehouse', function () {
    $warehouse = Warehouse::default();
    expect(fn () => $warehouse->delete())->toThrow(ValidationException::class);
    expect(fn () => $warehouse->update(['is_active' => false]))->toThrow(ValidationException::class);
    expect($warehouse->fresh()->is_active)->toBeTrue();
});

it('setting another warehouse as default unsets the old one', function () {
    $old = Warehouse::default();
    $next = Warehouse::factory()->create();
    $next->update(['is_default' => true]);
    expect($old->fresh()->is_default)->toBeFalse()
        ->and(Warehouse::default()->id)->toBe($next->id)
        ->and(Warehouse::where('is_default', true)->count())->toBe(1);
});

it('keeps an active default when creating or updating warehouses', function () {
    expect(fn () => Warehouse::default()->update(['is_default' => false]))->toThrow(ValidationException::class);
    expect(fn () => Warehouse::factory()->create(['is_default' => true, 'is_active' => false]))->toThrow(ValidationException::class);
});

it('creates zero stock in the default warehouse for new non manufactured products', function () {
    $product = Product::factory()->create(['type' => ProductType::RawMaterial]);
    expect(InventoryItem::where('product_id', $product->id)->first()->warehouse_id)->toBe(Warehouse::default()->id);
    expect((float) $product->inventoryItem->quantity)->toBe(0.0);
    $menu = Product::factory()->create(['type' => ProductType::Manufactured]);
    expect($menu->inventoryItem)->toBeNull();
});

it('lists only active warehouses through the active scope', function () {
    $inactive = Warehouse::factory()->create(['is_active' => false]);
    expect(Warehouse::active()->pluck('id'))->not->toContain($inactive->id);
});

it('uses warehouse permissions through its discovered policy', function () {
    foreach (['view', 'create', 'update', 'delete'] as $action) {
        expect(collect(\Database\Seeders\RolesAndPermissionsSeeder::permissionsCatalog())->contains(fn ($group) => array_key_exists($action.'_warehouses', $group)))->toBeTrue();
    }
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $user = \App\Models\User::factory()->viewer()->create();
    $policy = \Illuminate\Support\Facades\Gate::getPolicyFor(Warehouse::class);
    expect($policy)->toBeInstanceOf(\App\Policies\WarehousePolicy::class)
        ->and($policy->create($user))->toBeFalse();
    $user->givePermissionTo('create_warehouses');
    expect($policy->create($user))->toBeTrue();
});

it('renders the warehouse admin pages and creates and edits warehouses', function () {
    $this->actingAs(\App\Models\User::factory()->admin()->create());
    \Pest\Livewire\livewire(\App\Filament\Resources\Warehouses\Pages\ListWarehouses::class)
        ->assertSuccessful()->assertCanSeeTableRecords(Warehouse::all());
    \Pest\Livewire\livewire(\App\Filament\Resources\Warehouses\Pages\CreateWarehouse::class)
        ->fillForm(['name' => 'مخزن التجهيزات', 'is_active' => true, 'is_default' => false])
        ->call('create')->assertHasNoFormErrors();
    $warehouse = Warehouse::where('name', 'مخزن التجهيزات')->sole();
    \Pest\Livewire\livewire(\App\Filament\Resources\Warehouses\Pages\EditWarehouse::class, ['record' => $warehouse->getRouteKey()])
        ->fillForm(['name' => 'مخزن الشواية', 'is_default' => true])
        ->call('save')->assertHasNoFormErrors();
    expect(Warehouse::default()->id)->toBe($warehouse->id)
        ->and($warehouse->fresh()->name)->toBe('مخزن الشواية');
});
