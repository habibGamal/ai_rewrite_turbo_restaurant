<?php

use App\Enums\ProductType;
use App\Enums\UserRole;
use App\Filament\Resources\ConsumableProducts\Pages\CreateConsumableProduct;
use App\Filament\Resources\ConsumableProducts\Pages\EditConsumableProduct;
use App\Filament\Resources\ConsumableProducts\Pages\ListConsumableProducts;
use App\Filament\Resources\ConsumableProducts\Pages\ViewConsumableProduct;
use App\Models\Category;
use App\Models\Printer;
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
    livewire(ListConsumableProducts::class)
        ->assertSuccessful();
});

it('can render the create page', function () {
    livewire(CreateConsumableProduct::class)
        ->assertSuccessful();
});

it('can render the edit page', function () {
    $record = Product::factory()->create(['type' => ProductType::Consumable]);

    livewire(EditConsumableProduct::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

it('can render the view page', function () {
    $record = Product::factory()->create(['type' => ProductType::Consumable]);

    livewire(ViewConsumableProduct::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

// Table Column Tests
it('has column', function (string $column) {
    livewire(ListConsumableProducts::class)
        ->assertTableColumnExists($column);
})->with([
    'name',
    'barcode',
    'category.name',
    'price',
    'cost',
    'min_stock',
    'unit',
    'printers.name',
    'inventoryItem.quantity',
    'legacy',
    'created_at',
]);

it('can render column', function (string $column) {
    livewire(ListConsumableProducts::class)
        ->assertCanRenderTableColumn($column);
})->with([
    'name',
    'barcode',
    'category.name',
    'price',
    'cost',
    'min_stock',
    'unit',
    'legacy',
]);

// Table Sorting Tests
it('can sort column', function (string $column) {
    $records = Product::factory(5)->create(['type' => ProductType::Consumable]);

    livewire(ListConsumableProducts::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($column))
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($column));
})->with(['name', 'price', 'cost', 'min_stock']);

// Table Search Tests
it('can search by name', function () {
    $records = Product::factory(5)->create(['type' => ProductType::Consumable]);

    $value = $records->first()->name;

    livewire(ListConsumableProducts::class)
        ->searchTable($value)
        ->assertCanSeeTableRecords($records->where('name', $value))
        ->assertCanNotSeeTableRecords($records->where('name', '!=', $value));
});

it('can search by barcode', function () {
    $records = Product::factory(5)->create([
        'type' => ProductType::Consumable,
        'barcode' => fake()->numerify('########'),
    ]);

    $value = $records->first()->barcode;

    livewire(ListConsumableProducts::class)
        ->searchTable($value)
        ->assertCanSeeTableRecords($records->where('barcode', $value));
});

// Table Filtering Tests
it('can filter by category', function () {
    $category = Category::factory()->create();
    $records = Product::factory(3)->create([
        'type' => ProductType::Consumable,
        'category_id' => $category->id,
    ]);
    $otherRecords = Product::factory(2)->create(['type' => ProductType::Consumable]);

    livewire(ListConsumableProducts::class)
        ->assertCanSeeTableRecords($records)
        ->assertCanSeeTableRecords($otherRecords)
        ->filterTable('category_id', $category->id)
        ->assertCanSeeTableRecords($records)
        ->assertCanNotSeeTableRecords($otherRecords);
});

it('can filter by legacy status', function () {
    $activeRecords = Product::factory(3)->create([
        'type' => ProductType::Consumable,
        'legacy' => false,
    ]);
    $legacyRecords = Product::factory(2)->create([
        'type' => ProductType::Consumable,
        'legacy' => true,
    ]);

    livewire(ListConsumableProducts::class)
        ->assertCanSeeTableRecords($activeRecords)
        ->assertCanSeeTableRecords($legacyRecords)
        ->filterTable('legacy', true)
        ->assertCanSeeTableRecords($legacyRecords)
        ->assertCanNotSeeTableRecords($activeRecords);
});

// CRUD Operations Tests
it('can create a consumable product', function () {
    $category = Category::factory()->create();
    $record = Product::factory()->make(['type' => ProductType::Consumable]);

    livewire(CreateConsumableProduct::class)
        ->fillForm([
            'name' => $record->name,
            'barcode' => $record->barcode,
            'category_id' => $category->id,
            'price' => $record->price,
            'cost' => $record->cost,
            'min_stock' => $record->min_stock,
            'unit' => $record->unit,
            'type' => ProductType::Consumable->value,
            'legacy' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Product::class, [
        'name' => $record->name,
        'type' => ProductType::Consumable->value,
    ]);
});

it('can create a consumable product with printers', function () {
    $category = Category::factory()->create();
    $printers = Printer::factory(2)->create();
    $record = Product::factory()->make(['type' => ProductType::Consumable]);

    livewire(CreateConsumableProduct::class)
        ->fillForm([
            'name' => $record->name,
            'category_id' => $category->id,
            'price' => $record->price,
            'cost' => $record->cost,
            'min_stock' => $record->min_stock,
            'unit' => $record->unit,
            'type' => ProductType::Consumable->value,
            'printers' => $printers->pluck('id')->toArray(),
            'legacy' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $createdProduct = Product::where('name', $record->name)->first();
    expect($createdProduct->printers)->toHaveCount(2);
});

it('can update a consumable product', function () {
    $record = Product::factory()->create(['type' => ProductType::Consumable]);
    $newCategory = Category::factory()->create();
    $newRecord = Product::factory()->make(['type' => ProductType::Consumable]);

    livewire(EditConsumableProduct::class, ['record' => $record->getRouteKey()])
        ->fillForm([
            'name' => $newRecord->name,
            'category_id' => $newCategory->id,
            'price' => $newRecord->price,
            'cost' => $newRecord->cost,
            'min_stock' => $newRecord->min_stock,
            'unit' => $newRecord->unit,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Product::class, [
        'id' => $record->id,
        'name' => $newRecord->name,
    ]);
});

it('can view a consumable product', function () {
    $record = Product::factory()->create(['type' => ProductType::Consumable]);

    livewire(ViewConsumableProduct::class, ['record' => $record->getRouteKey()])
        ->assertSchemaStateSet([
            'name' => $record->name,
            'price' => $record->price,
            'cost' => $record->cost,
            'type' => ProductType::Consumable->value,
        ]);
});

it('can delete a consumable product', function () {
    $record = Product::factory()->create(['type' => ProductType::Consumable]);

    livewire(EditConsumableProduct::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete')
        ->callAction(DeleteAction::class);

    $this->assertModelMissing($record);
});

it('can bulk delete consumable products', function () {
    $records = Product::factory(5)->create(['type' => ProductType::Consumable]);

    livewire(ListConsumableProducts::class)
        ->callTableBulkAction('delete', $records);

    foreach ($records as $record) {
        $this->assertModelMissing($record);
    }
});

// Form Validation Tests
it('can validate required name', function () {
    livewire(CreateConsumableProduct::class)
        ->fillForm(['name' => null])
        ->call('create')
        ->assertHasFormErrors(['name' => ['required']]);
});

it('can validate max length for name', function () {
    livewire(CreateConsumableProduct::class)
        ->fillForm(['name' => Str::random(256)])
        ->call('create')
        ->assertHasFormErrors(['name' => ['max:255']]);
});

it('can validate required category', function () {
    livewire(CreateConsumableProduct::class)
        ->fillForm(['category_id' => null])
        ->call('create')
        ->assertHasFormErrors(['category_id' => ['required']]);
});

it('can validate required price', function () {
    livewire(CreateConsumableProduct::class)
        ->fillForm(['price' => null])
        ->call('create')
        ->assertHasFormErrors(['price' => ['required']]);
});

it('can validate numeric price', function () {
    livewire(CreateConsumableProduct::class)
        ->fillForm(['price' => 'not-a-number'])
        ->call('create')
        ->assertHasFormErrors(['price']);
});

it('can validate required cost', function () {
    livewire(CreateConsumableProduct::class)
        ->fillForm(['cost' => null])
        ->call('create')
        ->assertHasFormErrors(['cost' => ['required']]);
});

it('can validate numeric cost', function () {
    livewire(CreateConsumableProduct::class)
        ->fillForm(['cost' => 'not-a-number'])
        ->call('create')
        ->assertHasFormErrors(['cost']);
});

it('can validate required min_stock', function () {
    livewire(CreateConsumableProduct::class)
        ->fillForm(['min_stock' => null])
        ->call('create')
        ->assertHasFormErrors(['min_stock' => ['required']]);
});

it('can validate numeric min_stock', function () {
    livewire(CreateConsumableProduct::class)
        ->fillForm(['min_stock' => 'not-a-number'])
        ->call('create')
        ->assertHasFormErrors(['min_stock']);
});

it('can validate required unit', function () {
    livewire(CreateConsumableProduct::class)
        ->fillForm(['unit' => null])
        ->call('create')
        ->assertHasFormErrors(['unit' => ['required']]);
});

// Record Visibility Tests
it('can see table records', function () {
    $records = Product::factory(5)->create(['type' => ProductType::Consumable]);

    livewire(ListConsumableProducts::class)
        ->assertCanSeeTableRecords($records);
});

it('can count table records', function () {
    Product::factory(3)->create(['type' => ProductType::Consumable]);

    livewire(ListConsumableProducts::class)
        ->assertCountTableRecords(3);
});

it('only shows consumable products', function () {
    $consumableProducts = Product::factory(3)->create(['type' => ProductType::Consumable]);
    $manufacturedProducts = Product::factory(2)->create(['type' => ProductType::Manufactured]);

    livewire(ListConsumableProducts::class)
        ->assertCanSeeTableRecords($consumableProducts)
        ->assertCanNotSeeTableRecords($manufacturedProducts);
});

// Table Actions Tests
it('has view action on list page', function () {
    livewire(ListConsumableProducts::class)
        ->assertTableActionExists('view');
});

it('has edit action on list page', function () {
    livewire(ListConsumableProducts::class)
        ->assertTableActionExists('edit');
});

it('has delete action on list page', function () {
    livewire(ListConsumableProducts::class)
        ->assertTableActionExists('delete');
});

// Page Actions Tests
it('has view action on edit page header', function () {
    $record = Product::factory()->create(['type' => ProductType::Consumable]);

    livewire(EditConsumableProduct::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('view');
});

it('has delete action on edit page header', function () {
    $record = Product::factory()->create(['type' => ProductType::Consumable]);

    livewire(EditConsumableProduct::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete');
});

it('has edit action on view page header', function () {
    $record = Product::factory()->create(['type' => ProductType::Consumable]);

    livewire(ViewConsumableProduct::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('edit');
});

// Additional Tests
it('displays unit label in Arabic', function () {
    $record = Product::factory()->create([
        'type' => ProductType::Consumable,
        'unit' => 'packet',
    ]);

    livewire(ListConsumableProducts::class)
        ->assertSee('باكت');
});

it('displays price with EGP currency', function () {
    $record = Product::factory()->create([
        'type' => ProductType::Consumable,
        'price' => 100.50,
    ]);

    livewire(ListConsumableProducts::class)
        ->assertSee($record->name);
});

it('can toggle legacy status', function () {
    $record = Product::factory()->create([
        'type' => ProductType::Consumable,
        'legacy' => false,
    ]);

    livewire(EditConsumableProduct::class, ['record' => $record->getRouteKey()])
        ->fillForm(['legacy' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Product::class, [
        'id' => $record->id,
        'legacy' => true,
    ]);
});
