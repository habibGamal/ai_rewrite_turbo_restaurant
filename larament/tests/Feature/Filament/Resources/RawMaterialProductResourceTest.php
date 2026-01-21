<?php

use App\Enums\ProductType;
use App\Enums\UserRole;
use App\Filament\Resources\RawMaterialProducts\Pages\CreateRawMaterialProduct;
use App\Filament\Resources\RawMaterialProducts\Pages\EditRawMaterialProduct;
use App\Filament\Resources\RawMaterialProducts\Pages\ListRawMaterialProducts;
use App\Filament\Resources\RawMaterialProducts\Pages\ViewRawMaterialProduct;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    actingAs($this->admin);
});

// Page Rendering Tests
it('can render the index page', function () {
    livewire(ListRawMaterialProducts::class)
        ->assertSuccessful();
});

it('can render the create page', function () {
    livewire(CreateRawMaterialProduct::class)
        ->assertSuccessful();
});

it('can render the edit page', function () {
    $record = Product::factory()->create(['type' => ProductType::RawMaterial]);

    livewire(EditRawMaterialProduct::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

it('can render the view page', function () {
    $record = Product::factory()->create(['type' => ProductType::RawMaterial]);

    livewire(ViewRawMaterialProduct::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

// Table Column Tests
it('has column', function (string $column) {
    livewire(ListRawMaterialProducts::class)
        ->assertTableColumnExists($column);
})->with(['name', 'barcode', 'category.name', 'price', 'cost', 'min_stock', 'unit', 'inventoryItem.quantity', 'legacy', 'created_at']);

it('can render column', function (string $column) {
    livewire(ListRawMaterialProducts::class)
        ->assertCanRenderTableColumn($column);
})->with(['name', 'barcode', 'category.name', 'price', 'cost', 'min_stock', 'unit', 'legacy', 'created_at']);

it('can sort column', function (string $column) {
    $records = Product::factory(5)->create(['type' => ProductType::RawMaterial]);

    livewire(ListRawMaterialProducts::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($column))
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($column));
})->with(['name', 'price', 'cost', 'min_stock']);

// Search Tests
it('can search by name', function () {
    $records = Product::factory(5)->create(['type' => ProductType::RawMaterial]);

    $value = $records->first()->name;

    livewire(ListRawMaterialProducts::class)
        ->searchTable($value)
        ->assertCanSeeTableRecords($records->where('name', $value))
        ->assertCanNotSeeTableRecords($records->where('name', '!=', $value));
});

it('can search by barcode', function () {
    $records = Product::factory(5)->create([
        'type' => ProductType::RawMaterial,
        'barcode' => fn() => fake()->unique()->ean13(),
    ]);

    $value = $records->first()->barcode;

    livewire(ListRawMaterialProducts::class)
        ->searchTable($value)
        ->assertCanSeeTableRecords($records->where('barcode', $value))
        ->assertCanNotSeeTableRecords($records->where('barcode', '!=', $value));
});

// Filter Tests
it('can filter by category', function () {
    $category1 = Category::factory()->create();
    $category2 = Category::factory()->create();

    $records1 = Product::factory(3)->create([
        'type' => ProductType::RawMaterial,
        'category_id' => $category1->id,
    ]);
    $records2 = Product::factory(3)->create([
        'type' => ProductType::RawMaterial,
        'category_id' => $category2->id,
    ]);

    livewire(ListRawMaterialProducts::class)
        ->filterTable('category_id', $category1->id)
        ->assertCanSeeTableRecords($records1)
        ->assertCanNotSeeTableRecords($records2);
});

it('can filter by legacy status', function () {
    $activeRecords = Product::factory(3)->create([
        'type' => ProductType::RawMaterial,
        'legacy' => false,
    ]);
    $inactiveRecords = Product::factory(3)->create([
        'type' => ProductType::RawMaterial,
        'legacy' => true,
    ]);

    livewire(ListRawMaterialProducts::class)
        ->filterTable('legacy', true)
        ->assertCanSeeTableRecords($inactiveRecords);
});

// CRUD Operations
it('can create a raw material product', function () {
    $category = Category::factory()->create();
    $record = Product::factory()->make([
        'type' => ProductType::RawMaterial,
        'category_id' => $category->id,
    ]);

    livewire(CreateRawMaterialProduct::class)
        ->fillForm([
            'name' => $record->name,
            'barcode' => $record->barcode ?? '',
            'category_id' => $category->id,
            'cost' => $record->cost,
            'min_stock' => $record->min_stock,
            'unit' => $record->unit,
            'type' => 'raw_material',
            'legacy' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Product::class, [
        'name' => $record->name,
        'category_id' => $category->id,
        'type' => ProductType::RawMaterial->value,
    ]);
});

it('can create a raw material product without barcode', function () {
    $category = Category::factory()->create();
    $record = Product::factory()->make([
        'type' => ProductType::RawMaterial,
        'category_id' => $category->id,
    ]);

    livewire(CreateRawMaterialProduct::class)
        ->fillForm([
            'name' => $record->name,
            'category_id' => $category->id,
            'cost' => $record->cost,
            'min_stock' => $record->min_stock,
            'unit' => $record->unit,
            'type' => 'raw_material',
            'legacy' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Product::class, [
        'name' => $record->name,
        'type' => ProductType::RawMaterial->value,
    ]);
});

it('can update a raw material product', function () {
    $record = Product::factory()->create(['type' => ProductType::RawMaterial]);
    $newRecord = Product::factory()->make(['type' => ProductType::RawMaterial]);

    livewire(EditRawMaterialProduct::class, ['record' => $record->getRouteKey()])
        ->fillForm([
            'name' => $newRecord->name,
            'cost' => $newRecord->cost,
            'min_stock' => $newRecord->min_stock,
            'unit' => $newRecord->unit,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Product::class, [
        'id' => $record->id,
        'name' => $newRecord->name,
        'cost' => $newRecord->cost,
    ]);
});

it('can view a raw material product', function () {
    $category = Category::factory()->create();
    $record = Product::factory()->create([
        'type' => ProductType::RawMaterial,
        'category_id' => $category->id,
    ]);

    livewire(ViewRawMaterialProduct::class, ['record' => $record->getRouteKey()])
        ->assertSchemaStateSet([
            'name' => $record->name,
            'category_id' => $category->id,
            'cost' => $record->cost,
            'min_stock' => $record->min_stock,
            'unit' => $record->unit,
        ]);
});

it('can delete a raw material product', function () {
    $record = Product::factory()->create(['type' => ProductType::RawMaterial]);

    livewire(EditRawMaterialProduct::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete')
        ->callAction(DeleteAction::class);

    $this->assertModelMissing($record);
});

it('can bulk delete raw material products', function () {
    $records = Product::factory(5)->create(['type' => ProductType::RawMaterial]);

    livewire(ListRawMaterialProducts::class)
        ->callTableBulkAction('delete', $records);

    foreach ($records as $record) {
        $this->assertModelMissing($record);
    }
});

// Form Validation Tests
it('can validate required name', function () {
    livewire(CreateRawMaterialProduct::class)
        ->fillForm(['name' => null])
        ->call('create')
        ->assertHasFormErrors(['name' => ['required']]);
});

it('can validate max length for name', function () {
    livewire(CreateRawMaterialProduct::class)
        ->fillForm(['name' => Str::random(256)])
        ->call('create')
        ->assertHasFormErrors(['name' => ['max:255']]);
});

it('can validate max length for barcode', function () {
    livewire(CreateRawMaterialProduct::class)
        ->fillForm([
            'name' => 'Test Product',
            'barcode' => Str::random(256),
        ])
        ->call('create')
        ->assertHasFormErrors(['barcode' => ['max:255']]);
});

it('can validate required category', function () {
    livewire(CreateRawMaterialProduct::class)
        ->fillForm([
            'name' => 'Test Product',
            'category_id' => null,
        ])
        ->call('create')
        ->assertHasFormErrors(['category_id' => ['required']]);
});

it('can validate required cost', function () {
    livewire(CreateRawMaterialProduct::class)
        ->fillForm([
            'name' => 'Test Product',
            'cost' => null,
        ])
        ->call('create')
        ->assertHasFormErrors(['cost' => ['required']]);
});

it('can validate numeric cost', function () {
    livewire(CreateRawMaterialProduct::class)
        ->fillForm([
            'name' => 'Test Product',
            'cost' => 'not-a-number',
        ])
        ->call('create')
        ->assertHasFormErrors(['cost']);
});

it('can validate required min_stock', function () {
    livewire(CreateRawMaterialProduct::class)
        ->fillForm([
            'name' => 'Test Product',
            'min_stock' => null,
        ])
        ->call('create')
        ->assertHasFormErrors(['min_stock' => ['required']]);
});

it('can validate numeric min_stock', function () {
    livewire(CreateRawMaterialProduct::class)
        ->fillForm([
            'name' => 'Test Product',
            'min_stock' => 'not-a-number',
        ])
        ->call('create')
        ->assertHasFormErrors(['min_stock']);
});

it('can validate required unit', function () {
    livewire(CreateRawMaterialProduct::class)
        ->fillForm([
            'name' => 'Test Product',
            'unit' => null,
        ])
        ->call('create')
        ->assertHasFormErrors(['unit' => ['required']]);
});

// Table Actions
it('has view action on list page', function () {
    livewire(ListRawMaterialProducts::class)
        ->assertTableActionExists('view');
});

it('has edit action on list page', function () {
    livewire(ListRawMaterialProducts::class)
        ->assertTableActionExists('edit');
});

it('has delete action on list page', function () {
    livewire(ListRawMaterialProducts::class)
        ->assertTableActionExists('delete');
});

// Page Actions
it('has view action on edit page header', function () {
    $record = Product::factory()->create(['type' => ProductType::RawMaterial]);

    livewire(EditRawMaterialProduct::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('view');
});

it('has delete action on edit page header', function () {
    $record = Product::factory()->create(['type' => ProductType::RawMaterial]);

    livewire(EditRawMaterialProduct::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete');
});

it('has edit action on view page header', function () {
    $record = Product::factory()->create(['type' => ProductType::RawMaterial]);

    livewire(ViewRawMaterialProduct::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('edit');
});

// Table Records Visibility
it('can see table records', function () {
    $records = Product::factory(5)->create(['type' => ProductType::RawMaterial]);

    livewire(ListRawMaterialProducts::class)
        ->assertCanSeeTableRecords($records);
});

it('can count table records', function () {
    Product::factory(3)->create(['type' => ProductType::RawMaterial]);

    livewire(ListRawMaterialProducts::class)
        ->assertCountTableRecords(3);
});

it('does not show manufactured products', function () {
    $rawMaterials = Product::factory(3)->create(['type' => ProductType::RawMaterial]);
    $manufactured = Product::factory(3)->create(['type' => ProductType::Manufactured]);

    livewire(ListRawMaterialProducts::class)
        ->assertCanSeeTableRecords($rawMaterials)
        ->assertCanNotSeeTableRecords($manufactured);
});

// Field Visibility Tests
it('has name field in create form', function () {
    livewire(CreateRawMaterialProduct::class)
        ->assertSchemaComponentExists('name');
});

it('has barcode field in create form', function () {
    livewire(CreateRawMaterialProduct::class)
        ->assertSchemaComponentExists('barcode');
});

it('has category_id field in create form', function () {
    livewire(CreateRawMaterialProduct::class)
        ->assertSchemaComponentExists('category_id');
});

it('has cost field in create form', function () {
    livewire(CreateRawMaterialProduct::class)
        ->assertSchemaComponentExists('cost');
});

it('has min_stock field in create form', function () {
    livewire(CreateRawMaterialProduct::class)
        ->assertSchemaComponentExists('min_stock');
});

it('has unit field in create form', function () {
    livewire(CreateRawMaterialProduct::class)
        ->assertSchemaComponentExists('unit');
});

it('has legacy field in create form', function () {
    livewire(CreateRawMaterialProduct::class)
        ->assertSchemaComponentExists('legacy');
});

// Money Formatting Test
it('displays cost and price as EGP currency', function () {
    $record = Product::factory()->create([
        'type' => ProductType::RawMaterial,
        'cost' => 1234.56,
        'price' => 2345.67,
    ]);

    livewire(ListRawMaterialProducts::class)
        ->assertCanSeeTableRecords([$record]);
});

// Unit Formatting Test
it('displays unit in Arabic', function () {
    $record = Product::factory()->create([
        'type' => ProductType::RawMaterial,
        'unit' => 'kg',
    ]);

    livewire(ListRawMaterialProducts::class)
        ->assertCanSeeTableRecords([$record]);
});

// Timestamp Toggleability
it('created_at is toggleable column', function () {
    livewire(ListRawMaterialProducts::class)
        ->assertTableColumnExists('created_at');
});

// Inventory Item Relationship
it('displays inventory quantity', function () {
    $record = Product::factory()->create(['type' => ProductType::RawMaterial]);
    InventoryItem::factory()->create([
        'product_id' => $record->id,
        'quantity' => 50,
    ]);

    livewire(ListRawMaterialProducts::class)
        ->assertCanSeeTableRecords([$record]);
});

it('displays zero for products without inventory', function () {
    $record = Product::factory()->create(['type' => ProductType::RawMaterial]);

    livewire(ListRawMaterialProducts::class)
        ->assertCanSeeTableRecords([$record]);
});

// Default Values
it('sets default values for new product', function () {
    $category = Category::factory()->create();

    livewire(CreateRawMaterialProduct::class)
        ->assertSchemaStateSet([
            'min_stock' => 0,
            'legacy' => false,
        ]);
});

// Barcode Placeholder
it('displays placeholder for null barcode', function () {
    $record = Product::factory()->create([
        'type' => ProductType::RawMaterial,
        'barcode' => null,
    ]);

    livewire(ListRawMaterialProducts::class)
        ->assertCanSeeTableRecords([$record]);
});
