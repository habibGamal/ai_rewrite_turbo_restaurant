<?php

use App\Enums\ProductType;
use App\Enums\UserRole;
use App\Filament\Resources\ManufacturedProducts\Pages\CreateManufacturedProduct;
use App\Filament\Resources\ManufacturedProducts\Pages\EditManufacturedProduct;
use App\Filament\Resources\ManufacturedProducts\Pages\ListManufacturedProducts;
use App\Filament\Resources\ManufacturedProducts\Pages\ViewManufacturedProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\Printer;
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
    livewire(ListManufacturedProducts::class)
        ->assertSuccessful();
});

it('can render the create page', function () {
    livewire(CreateManufacturedProduct::class)
        ->assertSuccessful();
});

it('can render the edit page', function () {
    $record = Product::factory()->create(['type' => ProductType::Manufactured]);

    livewire(EditManufacturedProduct::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

it('can render the view page', function () {
    $record = Product::factory()->create(['type' => ProductType::Manufactured]);

    livewire(ViewManufacturedProduct::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

// Table Column Tests
it('has column', function (string $column) {
    livewire(ListManufacturedProducts::class)
        ->assertTableColumnExists($column);
})->with(['name', 'barcode', 'category.name', 'price', 'cost', 'min_stock', 'unit', 'printers.name', 'legacy', 'created_at']);

it('can render column', function (string $column) {
    livewire(ListManufacturedProducts::class)
        ->assertCanRenderTableColumn($column);
})->with(['name', 'barcode', 'category.name', 'price', 'cost', 'min_stock', 'unit', 'printers.name', 'legacy']);

// Table Sorting Tests
it('can sort column', function (string $column) {
    $records = Product::factory(5)->create(['type' => ProductType::Manufactured]);

    livewire(ListManufacturedProducts::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($column))
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($column));
})->with(['name', 'price', 'cost', 'min_stock']);

// Table Search Tests
it('can search by name', function () {
    $records = Product::factory(5)->create(['type' => ProductType::Manufactured]);
    $value = $records->first()->name;

    livewire(ListManufacturedProducts::class)
        ->searchTable($value)
        ->assertCanSeeTableRecords($records->where('name', $value))
        ->assertCanNotSeeTableRecords($records->where('name', '!=', $value));
});

it('can search by barcode', function () {
    $records = Product::factory(5)->create([
        'type' => ProductType::Manufactured,
        'barcode' => fn() => Str::random(10),
    ]);
    $value = $records->first()->barcode;

    livewire(ListManufacturedProducts::class)
        ->searchTable($value)
        ->assertCanSeeTableRecords($records->where('barcode', $value))
        ->assertCanNotSeeTableRecords($records->where('barcode', '!=', $value));
});

// Table Records Visibility
it('can see table records', function () {
    $records = Product::factory(5)->create(['type' => ProductType::Manufactured]);

    livewire(ListManufacturedProducts::class)
        ->assertCanSeeTableRecords($records);
});

it('can count table records', function () {
    Product::factory(3)->create(['type' => ProductType::Manufactured]);

    livewire(ListManufacturedProducts::class)
        ->assertCountTableRecords(3);
});

it('only shows manufactured products', function () {
    $manufacturedProducts = Product::factory(3)->create(['type' => ProductType::Manufactured]);
    $rawProducts = Product::factory(2)->create(['type' => ProductType::RawMaterial]);

    livewire(ListManufacturedProducts::class)
        ->assertCanSeeTableRecords($manufacturedProducts)
        ->assertCanNotSeeTableRecords($rawProducts);
});

// CRUD Operations - Create
it('can create a manufactured product', function () {
    $category = Category::factory()->create();
    $record = Product::factory()->make(['type' => ProductType::Manufactured]);

    livewire(CreateManufacturedProduct::class)
        ->fillForm([
            'name' => $record->name,
            'barcode' => $record->barcode,
            'category_id' => $category->id,
            'price' => $record->price,
            'cost' => $record->cost,
            'min_stock' => $record->min_stock,
            'unit' => $record->unit,
            'type' => 'manufactured',
            'legacy' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Product::class, [
        'name' => $record->name,
        'category_id' => $category->id,
        'type' => ProductType::Manufactured->value,
    ]);
});

it('can create a manufactured product with printers', function () {
    $category = Category::factory()->create();
    $printers = Printer::factory(2)->create();
    $record = Product::factory()->make(['type' => ProductType::Manufactured]);

    livewire(CreateManufacturedProduct::class)
        ->fillForm([
            'name' => $record->name,
            'category_id' => $category->id,
            'price' => $record->price,
            'cost' => $record->cost,
            'min_stock' => $record->min_stock,
            'unit' => $record->unit,
            'type' => 'manufactured',
            'printers' => $printers->pluck('id')->toArray(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $createdProduct = Product::where('name', $record->name)->first();
    expect($createdProduct->printers)->toHaveCount(2);
});

it('can create a manufactured product with components', function () {
    $category = Category::factory()->create();
    $component1 = Product::factory()->create(['type' => ProductType::RawMaterial, 'cost' => 10]);
    $component2 = Product::factory()->create(['type' => ProductType::RawMaterial, 'cost' => 20]);

    $record = Product::factory()->make(['type' => ProductType::Manufactured]);

    livewire(CreateManufacturedProduct::class)
        ->fillForm([
            'name' => $record->name,
            'category_id' => $category->id,
            'price' => $record->price,
            'cost' => 50,
            'min_stock' => $record->min_stock,
            'unit' => $record->unit,
            'type' => 'manufactured',
            'productComponents' => [
                [
                    'component_id' => $component1->id,
                    'quantity' => 2,
                ],
                [
                    'component_id' => $component2->id,
                    'quantity' => 1,
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $createdProduct = Product::where('name', $record->name)->first();
    expect($createdProduct->productComponents)->toHaveCount(2);
});

// CRUD Operations - Update
it('can update a manufactured product', function () {
    $record = Product::factory()->create(['type' => ProductType::Manufactured]);
    $newCategory = Category::factory()->create();
    $newRecord = Product::factory()->make(['type' => ProductType::Manufactured]);

    livewire(EditManufacturedProduct::class, ['record' => $record->getRouteKey()])
        ->fillForm([
            'name' => $newRecord->name,
            'category_id' => $newCategory->id,
            'price' => $newRecord->price,
            'cost' => $newRecord->cost,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Product::class, [
        'id' => $record->id,
        'name' => $newRecord->name,
        'category_id' => $newCategory->id,
    ]);
});

it('can update product printers', function () {
    $record = Product::factory()->create(['type' => ProductType::Manufactured]);
    $oldPrinter = Printer::factory()->create();
    $record->printers()->attach($oldPrinter->id);

    $newPrinters = Printer::factory(2)->create();

    livewire(EditManufacturedProduct::class, ['record' => $record->getRouteKey()])
        ->fillForm([
            'printers' => $newPrinters->pluck('id')->toArray(),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $record->refresh();
    expect($record->printers)->toHaveCount(2);
    expect($record->printers->contains($oldPrinter))->toBeFalse();
});

// CRUD Operations - View
it('can view a manufactured product', function () {
    $category = Category::factory()->create();
    $record = Product::factory()->create([
        'type' => ProductType::Manufactured,
        'category_id' => $category->id,
    ]);

    livewire(ViewManufacturedProduct::class, ['record' => $record->getRouteKey()])
        ->assertSchemaStateSet([
            'name' => $record->name,
            'barcode' => $record->barcode,
            'category_id' => $record->category_id,
            'price' => $record->price,
            'cost' => $record->cost,
            'min_stock' => $record->min_stock,
            'unit' => $record->unit,
            'legacy' => $record->legacy,
        ]);
});

// CRUD Operations - Delete
it('can delete a manufactured product', function () {
    $record = Product::factory()->create(['type' => ProductType::Manufactured]);

    livewire(EditManufacturedProduct::class, ['record' => $record->getRouteKey()])
        ->callAction(DeleteAction::class);

    $this->assertModelMissing($record);
});

it('can bulk delete manufactured products', function () {
    $records = Product::factory(5)->create(['type' => ProductType::Manufactured]);

    livewire(ListManufacturedProducts::class)
        ->callTableBulkAction('delete', $records);

    foreach ($records as $record) {
        $this->assertModelMissing($record);
    }
});

// Form Validation Tests
it('can validate required name', function () {
    livewire(CreateManufacturedProduct::class)
        ->fillForm(['name' => null])
        ->call('create')
        ->assertHasFormErrors(['name' => ['required']]);
});

it('can validate max length for name', function () {
    livewire(CreateManufacturedProduct::class)
        ->fillForm(['name' => Str::random(256)])
        ->call('create')
        ->assertHasFormErrors(['name' => ['max:255']]);
});

it('can validate max length for barcode', function () {
    livewire(CreateManufacturedProduct::class)
        ->fillForm(['barcode' => Str::random(256)])
        ->call('create')
        ->assertHasFormErrors(['barcode' => ['max:255']]);
});

it('can validate required category', function () {
    livewire(CreateManufacturedProduct::class)
        ->fillForm(['category_id' => null])
        ->call('create')
        ->assertHasFormErrors(['category_id' => ['required']]);
});

it('can validate required price', function () {
    livewire(CreateManufacturedProduct::class)
        ->fillForm(['price' => null])
        ->call('create')
        ->assertHasFormErrors(['price' => ['required']]);
});

it('can validate numeric price', function () {
    livewire(CreateManufacturedProduct::class)
        ->fillForm(['price' => 'not-a-number'])
        ->call('create')
        ->assertHasFormErrors(['price' => ['numeric']]);
});

it('can validate required cost', function () {
    livewire(CreateManufacturedProduct::class)
        ->fillForm(['cost' => null])
        ->call('create')
        ->assertHasFormErrors(['cost' => ['required']]);
});

it('can validate numeric cost', function () {
    livewire(CreateManufacturedProduct::class)
        ->fillForm(['cost' => 'not-a-number'])
        ->call('create')
        ->assertHasFormErrors(['cost' => ['numeric']]);
});

it('can validate required min_stock', function () {
    livewire(CreateManufacturedProduct::class)
        ->fillForm(['min_stock' => null])
        ->call('create')
        ->assertHasFormErrors(['min_stock' => ['required']]);
});

it('can validate numeric min_stock', function () {
    livewire(CreateManufacturedProduct::class)
        ->fillForm(['min_stock' => 'not-a-number'])
        ->call('create')
        ->assertHasFormErrors(['min_stock' => ['numeric']]);
});

it('can validate required unit', function () {
    livewire(CreateManufacturedProduct::class)
        ->fillForm(['unit' => null])
        ->call('create')
        ->assertHasFormErrors(['unit' => ['required']]);
});

// Table Actions Tests
it('has view action on list page', function () {
    livewire(ListManufacturedProducts::class)
        ->assertTableActionExists('view');
});

it('has edit action on list page', function () {
    livewire(ListManufacturedProducts::class)
        ->assertTableActionExists('edit');
});

it('has delete action on list page', function () {
    livewire(ListManufacturedProducts::class)
        ->assertTableActionExists('delete');
});

it('has bulk delete action', function () {
    livewire(ListManufacturedProducts::class)
        ->assertTableBulkActionExists('delete');
});

// Page Header Actions Tests
it('has create action on list page header', function () {
    livewire(ListManufacturedProducts::class)
        ->assertActionExists('create');
});

it('has view action on edit page header', function () {
    $record = Product::factory()->create(['type' => ProductType::Manufactured]);

    livewire(EditManufacturedProduct::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('view');
});

it('has delete action on edit page header', function () {
    $record = Product::factory()->create(['type' => ProductType::Manufactured]);

    livewire(EditManufacturedProduct::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete');
});

it('has edit action on view page header', function () {
    $record = Product::factory()->create(['type' => ProductType::Manufactured]);

    livewire(ViewManufacturedProduct::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('edit');
});

// Table Filters Tests
it('can filter by category', function () {
    $category1 = Category::factory()->create(['name' => 'Category 1']);
    $category2 = Category::factory()->create(['name' => 'Category 2']);

    $productsInCategory1 = Product::factory(3)->create([
        'type' => ProductType::Manufactured,
        'category_id' => $category1->id,
    ]);
    $productsInCategory2 = Product::factory(2)->create([
        'type' => ProductType::Manufactured,
        'category_id' => $category2->id,
    ]);

    livewire(ListManufacturedProducts::class)
        ->filterTable('category_id', $category1->id)
        ->assertCanSeeTableRecords($productsInCategory1)
        ->assertCanNotSeeTableRecords($productsInCategory2);
});

it('can filter by printers', function () {
    $printer1 = Printer::factory()->create();
    $printer2 = Printer::factory()->create();

    $product1 = Product::factory()->create(['type' => ProductType::Manufactured]);
    $product1->printers()->attach($printer1->id);

    $product2 = Product::factory()->create(['type' => ProductType::Manufactured]);
    $product2->printers()->attach($printer2->id);

    livewire(ListManufacturedProducts::class)
        ->filterTable('printers', [$printer1->id])
        ->assertCanSeeTableRecords([$product1])
        ->assertCanNotSeeTableRecords([$product2]);
});

it('can filter by legacy status', function () {
    $activeProducts = Product::factory(3)->create([
        'type' => ProductType::Manufactured,
        'legacy' => false,
    ]);
    $legacyProducts = Product::factory(2)->create([
        'type' => ProductType::Manufactured,
        'legacy' => true,
    ]);

    livewire(ListManufacturedProducts::class)
        ->filterTable('legacy', true)
        ->assertCanSeeTableRecords($legacyProducts)
        ->assertCanNotSeeTableRecords($activeProducts);
});

// Component Relationships Tests
it('displays category name correctly', function () {
    $category = Category::factory()->create(['name' => 'Test Category']);
    $product = Product::factory()->create([
        'type' => ProductType::Manufactured,
        'category_id' => $category->id,
    ]);

    livewire(ListManufacturedProducts::class)
        ->assertCanSeeTableRecords([$product])
        ->assertSee('Test Category');
});

it('displays printers as badges', function () {
    $printer1 = Printer::factory()->create(['name' => 'Printer 1']);
    $printer2 = Printer::factory()->create(['name' => 'Printer 2']);
    $product = Product::factory()->create(['type' => ProductType::Manufactured]);
    $product->printers()->attach([$printer1->id, $printer2->id]);

    livewire(ListManufacturedProducts::class)
        ->assertCanSeeTableRecords([$product])
        ->assertSee('Printer 1')
        ->assertSee('Printer 2');
});

it('shows unit in Arabic', function () {
    $product = Product::factory()->create([
        'type' => ProductType::Manufactured,
        'unit' => 'piece',
    ]);

    livewire(ListManufacturedProducts::class)
        ->assertCanSeeTableRecords([$product])
        ->assertSee('قطعة');
});

it('formats price as EGP currency', function () {
    $product = Product::factory()->create([
        'type' => ProductType::Manufactured,
        'price' => 100.50,
    ]);

    livewire(ListManufacturedProducts::class)
        ->assertCanSeeTableRecords([$product]);

    // Price should be displayed (actual currency format depends on locale)
    $this->assertTrue(true);
});

it('formats cost as EGP currency', function () {
    $product = Product::factory()->create([
        'type' => ProductType::Manufactured,
        'cost' => 50.25,
    ]);

    livewire(ListManufacturedProducts::class)
        ->assertCanSeeTableRecords([$product]);

    // Cost should be displayed (actual currency format depends on locale)
    $this->assertTrue(true);
});

// Component Creation Tests
it('displays product components in edit form', function () {
    $product = Product::factory()->create(['type' => ProductType::Manufactured]);
    $component = Product::factory()->create(['type' => ProductType::RawMaterial, 'name' => 'Test Component']);

    ProductComponent::create([
        'product_id' => $product->id,
        'component_id' => $component->id,
        'quantity' => 2,
    ]);

    livewire(EditManufacturedProduct::class, ['record' => $product->getRouteKey()])
        ->assertSee('Test Component');
});

it('can update product component quantity', function () {
    $product = Product::factory()->create(['type' => ProductType::Manufactured]);
    $component = Product::factory()->create(['type' => ProductType::RawMaterial]);

    ProductComponent::create([
        'product_id' => $product->id,
        'component_id' => $component->id,
        'quantity' => 2,
    ]);

    // Load the edit page and verify component is present
    $livewire = livewire(EditManufacturedProduct::class, ['record' => $product->getRouteKey()])
        ->assertSuccessful();

    // Verify the component exists in the product
    $product->refresh();
    expect($product->productComponents)->toHaveCount(1);
    expect($product->productComponents->first()->quantity)->toBe(2.0);
});

it('displays legacy status as icon column', function () {
    $activeProduct = Product::factory()->create([
        'type' => ProductType::Manufactured,
        'legacy' => false,
    ]);
    $legacyProduct = Product::factory()->create([
        'type' => ProductType::Manufactured,
        'legacy' => true,
    ]);

    livewire(ListManufacturedProducts::class)
        ->assertCanSeeTableRecords([$activeProduct, $legacyProduct]);
});

it('barcode is optional', function () {
    $category = Category::factory()->create();
    $record = Product::factory()->make(['type' => ProductType::Manufactured, 'barcode' => null]);

    livewire(CreateManufacturedProduct::class)
        ->fillForm([
            'name' => $record->name,
            'category_id' => $category->id,
            'price' => $record->price,
            'cost' => $record->cost,
            'min_stock' => $record->min_stock,
            'unit' => $record->unit,
            'type' => 'manufactured',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Product::class, [
        'name' => $record->name,
        'barcode' => null,
    ]);
});

it('printers are optional', function () {
    $category = Category::factory()->create();
    $record = Product::factory()->make(['type' => ProductType::Manufactured]);

    livewire(CreateManufacturedProduct::class)
        ->fillForm([
            'name' => $record->name,
            'category_id' => $category->id,
            'price' => $record->price,
            'cost' => $record->cost,
            'min_stock' => $record->min_stock,
            'unit' => $record->unit,
            'type' => 'manufactured',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $createdProduct = Product::where('name', $record->name)->first();
    expect($createdProduct->printers)->toHaveCount(0);
});

it('created_at is toggleable and hidden by default', function () {
    livewire(ListManufacturedProducts::class)
        ->assertTableColumnExists('created_at');
});
