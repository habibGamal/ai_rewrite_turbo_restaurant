<?php

use App\Enums\ProductType;
use App\Modules\Restaurant\Filament\Resources\KitchenStations\Pages\CreateKitchenStation;
use App\Modules\Restaurant\Filament\Resources\KitchenStations\Pages\EditKitchenStation;
use App\Modules\Restaurant\Filament\Resources\KitchenStations\Pages\ListKitchenStations;
use App\Modules\Restaurant\Filament\Resources\KitchenStations\RelationManagers\CategoriesRelationManager;
use App\Modules\Restaurant\Filament\Resources\KitchenStations\RelationManagers\ProductsRelationManager;
use App\Models\Category;
use App\Models\KitchenStation;
use App\Models\Product;
use App\Models\User;
use App\Modules\Restaurant\Services\KitchenRoutingService;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
});

// Page Rendering Tests
it('can render the index page', function () {
    livewire(ListKitchenStations::class)
        ->assertSuccessful();
});

it('can render the create page', function () {
    livewire(CreateKitchenStation::class)
        ->assertSuccessful();
});

it('can render the edit page', function () {
    $record = KitchenStation::factory()->create();

    livewire(EditKitchenStation::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

// Access Tests (same policy gating as Printers)
it('forbids access without the view permission', function () {
    $user = User::factory()->cashier()->create();
    actingAs($user);

    livewire(ListKitchenStations::class)
        ->assertForbidden();
});

// Table Column Tests
it('has column', function (string $column) {
    livewire(ListKitchenStations::class)
        ->assertTableColumnExists($column);
})->with(['name', 'is_active', 'sort_order', 'categories_count', 'resolved_products_count', 'created_at', 'updated_at']);

it('can render column', function (string $column) {
    KitchenStation::factory()->create();

    livewire(ListKitchenStations::class)
        ->assertCanRenderTableColumn($column);
})->with(['name', 'is_active', 'sort_order', 'categories_count', 'resolved_products_count']);

// Default Sorting Test
it('sorts by sort_order ascending by default', function () {
    KitchenStation::factory()->create([
        'name' => 'المحطة الثالثة',
        'sort_order' => 3,
    ]);
    KitchenStation::factory()->create([
        'name' => 'المحطة الأولى',
        'sort_order' => 1,
    ]);
    KitchenStation::factory()->create([
        'name' => 'المحطة الثانية',
        'sort_order' => 2,
    ]);

    livewire(ListKitchenStations::class)
        ->assertCanSeeTableRecords(KitchenStation::orderBy('sort_order')->get(), inOrder: true);
});

// Categories Count Test
it('shows correct categories count', function () {
    $station = KitchenStation::factory()->create();
    Category::factory()->count(3)->create(['kitchen_station_id' => $station->id]);

    livewire(ListKitchenStations::class)
        ->assertSee($station->name)
        ->assertSee('3'); // Should see the count displayed
});

// CRUD Operations Tests
it('can create a kitchen station', function () {
    $record = KitchenStation::factory()->make();

    livewire(CreateKitchenStation::class)
        ->fillForm([
            'name' => $record->name,
            'is_active' => true,
            'sort_order' => 4,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(KitchenStation::class, [
        'name' => $record->name,
        'is_active' => true,
        'sort_order' => 4,
    ]);
});

it('defaults a new station to active with sort order 0', function () {
    livewire(CreateKitchenStation::class)
        ->fillForm([
            'name' => 'محطة جديدة',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(KitchenStation::class, [
        'name' => 'محطة جديدة',
        'is_active' => true,
        'sort_order' => 0,
    ]);
});

it('can update a kitchen station', function () {
    $record = KitchenStation::factory()->create();

    livewire(EditKitchenStation::class, ['record' => $record->getRouteKey()])
        ->fillForm([
            'name' => 'اسم جديد',
            'is_active' => false,
            'sort_order' => 9,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $record->refresh();
    expect($record->name)->toBe('اسم جديد');
    expect($record->is_active)->toBeFalse();
    expect($record->sort_order)->toBe(9);
});

it('can delete a kitchen station', function () {
    $record = KitchenStation::factory()->create();

    livewire(EditKitchenStation::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete')
        ->callAction(DeleteAction::class);

    $this->assertModelMissing($record);
});

it('can bulk delete kitchen stations', function () {
    $records = KitchenStation::factory(3)->create();

    livewire(ListKitchenStations::class)
        ->callTableBulkAction(DeleteBulkAction::class, $records);

    foreach ($records as $record) {
        $this->assertModelMissing($record);
    }
});

// Form Validation Tests
it('can validate required name', function () {
    livewire(CreateKitchenStation::class)
        ->fillForm(['name' => null])
        ->call('create')
        ->assertHasFormErrors(['name' => ['required']]);
});

it('can validate max length for name', function () {
    livewire(CreateKitchenStation::class)
        ->fillForm(['name' => Str::random(256)])
        ->call('create')
        ->assertHasFormErrors(['name' => ['max:255']]);
});

it('can see table records', function () {
    $records = KitchenStation::factory(5)->create();

    livewire(ListKitchenStations::class)
        ->assertCanSeeTableRecords($records);
});

it('can count table records', function () {
    KitchenStation::factory(3)->create();

    livewire(ListKitchenStations::class)
        ->assertCountTableRecords(3);
});

// Table Actions Tests
it('has edit action on list page', function () {
    KitchenStation::factory()->create();

    livewire(ListKitchenStations::class)
        ->assertTableActionExists('edit');
});

it('has delete action on list page', function () {
    KitchenStation::factory()->create();

    livewire(ListKitchenStations::class)
        ->assertTableActionExists('delete');
});

it('has create action on list page header', function () {
    livewire(ListKitchenStations::class)
        ->assertActionExists('create');
});

// Form Field Tests
it('has name field', function () {
    livewire(CreateKitchenStation::class)
        ->assertFormFieldExists('name');
});

it('has is_active field', function () {
    livewire(CreateKitchenStation::class)
        ->assertFormFieldExists('is_active');
});

it('has sort_order field', function () {
    livewire(CreateKitchenStation::class)
        ->assertFormFieldExists('sort_order');
});

// Relation Managers Tests
it('renders relation managers on edit page', function () {
    $station = KitchenStation::factory()->create();

    livewire(EditKitchenStation::class, ['record' => $station->getRouteKey()])
        ->assertSuccessful()
        ->assertSeeLivewire(CategoriesRelationManager::class);

    livewire(EditKitchenStation::class, [
        'record' => $station->getRouteKey(),
        'activeRelationManager' => 1,
    ])
        ->assertSuccessful()
        ->assertSeeLivewire(ProductsRelationManager::class);
});

it('associates multiple categories and moves categories from another station', function () {
    $station = KitchenStation::factory()->create(['name' => 'المحطة الأولى']);
    $otherStation = KitchenStation::factory()->create(['name' => 'المحطة الثانية']);

    $category1 = Category::factory()->create([
        'name' => 'مشروبات',
        'kitchen_station_id' => null,
    ]);
    $category2 = Category::factory()->create([
        'name' => 'حلويات',
        'kitchen_station_id' => $otherStation->id,
    ]);

    livewire(CategoriesRelationManager::class, [
        'ownerRecord' => $station,
        'pageClass' => EditKitchenStation::class,
    ])
        ->callTableAction('associate', data: [
            'recordId' => [$category1->id, $category2->id],
        ])
        ->assertHasNoTableActionErrors();

    expect($category1->refresh()->kitchen_station_id)->toBe($station->id);
    expect($category2->refresh()->kitchen_station_id)->toBe($station->id);
});

it('dissociates a category and sets kitchen_station_id to null', function () {
    $station = KitchenStation::factory()->create();
    $category = Category::factory()->create([
        'kitchen_station_id' => $station->id,
    ]);

    livewire(CategoriesRelationManager::class, [
        'ownerRecord' => $station,
        'pageClass' => EditKitchenStation::class,
    ])
        ->callTableAction('dissociate', $category)
        ->assertHasNoTableActionErrors();

    expect($category->refresh()->kitchen_station_id)->toBeNull();
});

it('bulk dissociates categories and sets kitchen_station_id to null', function () {
    $station = KitchenStation::factory()->create();
    $categories = Category::factory()->count(2)->create([
        'kitchen_station_id' => $station->id,
    ]);

    livewire(CategoriesRelationManager::class, [
        'ownerRecord' => $station,
        'pageClass' => EditKitchenStation::class,
    ])
        ->callTableBulkAction('dissociate', $categories)
        ->assertHasNoTableActionErrors();

    foreach ($categories as $cat) {
        expect($cat->refresh()->kitchen_station_id)->toBeNull();
    }
});

it('excludes raw materials and legacy products from product associate options', function () {
    $station = KitchenStation::factory()->create();
    $sellableProduct = Product::factory()->create([
        'type' => ProductType::Manufactured,
        'legacy' => false,
        'kitchen_station_id' => null,
    ]);
    $rawMaterialProduct = Product::factory()->create([
        'type' => ProductType::RawMaterial,
        'legacy' => false,
        'kitchen_station_id' => null,
    ]);
    $legacyProduct = Product::factory()->create([
        'type' => ProductType::Consumable,
        'legacy' => true,
        'kitchen_station_id' => null,
    ]);
    $alreadyOnStationProduct = Product::factory()->create([
        'type' => ProductType::Consumable,
        'legacy' => false,
        'kitchen_station_id' => $station->id,
    ]);

    $component = livewire(ProductsRelationManager::class, [
        'ownerRecord' => $station,
        'pageClass' => EditKitchenStation::class,
    ]);

    /** @var \Filament\Actions\AssociateAction $action */
    $action = $component->instance()->getTable()->getAction('associate');
    $select = $action->getRecordSelect();
    $options = $select->getOptions();

    expect(array_keys($options))->toContain($sellableProduct->id);
    expect(array_keys($options))->not->toContain($rawMaterialProduct->id);
    expect(array_keys($options))->not->toContain($legacyProduct->id);
    expect(array_keys($options))->not->toContain($alreadyOnStationProduct->id);

    if ($sellableProduct->category) {
        expect($options[$sellableProduct->id])->toContain($sellableProduct->category->name);
    }
});

it('associates products to kitchen station', function () {
    $station = KitchenStation::factory()->create();
    $product1 = Product::factory()->create([
        'type' => ProductType::Manufactured,
        'legacy' => false,
        'kitchen_station_id' => null,
    ]);
    $product2 = Product::factory()->create([
        'type' => ProductType::Consumable,
        'legacy' => false,
        'kitchen_station_id' => null,
    ]);

    livewire(ProductsRelationManager::class, [
        'ownerRecord' => $station,
        'pageClass' => EditKitchenStation::class,
    ])
        ->callTableAction('associate', data: [
            'recordId' => [$product1->id, $product2->id],
        ])
        ->assertHasNoTableActionErrors();

    expect($product1->refresh()->kitchen_station_id)->toBe($station->id);
    expect($product2->refresh()->kitchen_station_id)->toBe($station->id);
});

it('dissociates a product and restores inheritance from its category', function () {
    $stationA = KitchenStation::factory()->create(['name' => 'المحطة أ']);
    $stationB = KitchenStation::factory()->create(['name' => 'المحطة ب']);

    $category = Category::factory()->create([
        'kitchen_station_id' => $stationB->id,
    ]);
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'kitchen_station_id' => $stationA->id,
        'type' => ProductType::Manufactured,
        'legacy' => false,
    ]);

    $routingService = app(KitchenRoutingService::class);
    expect($routingService->stationId($product))->toBe($stationA->id);

    livewire(ProductsRelationManager::class, [
        'ownerRecord' => $stationA,
        'pageClass' => EditKitchenStation::class,
    ])
        ->callTableAction('dissociate', $product)
        ->assertHasNoTableActionErrors();

    $product->refresh();
    expect($product->kitchen_station_id)->toBeNull();
    expect($routingService->stationId($product))->toBe($stationB->id);
});

it('bulk dissociates products and restores inheritance from their category', function () {
    $stationA = KitchenStation::factory()->create(['name' => 'المحطة أ']);
    $stationB = KitchenStation::factory()->create(['name' => 'المحطة ب']);

    $category = Category::factory()->create([
        'kitchen_station_id' => $stationB->id,
    ]);
    $products = Product::factory()->count(2)->create([
        'category_id' => $category->id,
        'kitchen_station_id' => $stationA->id,
        'type' => ProductType::Manufactured,
        'legacy' => false,
    ]);

    livewire(ProductsRelationManager::class, [
        'ownerRecord' => $stationA,
        'pageClass' => EditKitchenStation::class,
    ])
        ->callTableBulkAction('dissociate', $products)
        ->assertHasNoTableActionErrors();

    $routingService = app(KitchenRoutingService::class);
    foreach ($products as $p) {
        $p->refresh();
        expect($p->kitchen_station_id)->toBeNull();
        expect($routingService->stationId($p))->toBe($stationB->id);
    }
});

it('calculates resolved products count correctly', function () {
    $stationA = KitchenStation::factory()->create(['name' => 'المحطة أ']);
    $stationB = KitchenStation::factory()->create(['name' => 'المحطة ب']);

    $categoryA = Category::factory()->create(['kitchen_station_id' => $stationA->id]);
    $categoryB = Category::factory()->create(['kitchen_station_id' => $stationB->id]);

    // 1. Inherits station A from categoryA
    Product::factory()->create([
        'category_id' => $categoryA->id,
        'kitchen_station_id' => null,
        'type' => ProductType::Manufactured,
        'legacy' => false,
    ]);

    // 2. Belongs to categoryA but overrides to stationB (must be excluded from A, included in B)
    Product::factory()->create([
        'category_id' => $categoryA->id,
        'kitchen_station_id' => $stationB->id,
        'type' => ProductType::Manufactured,
        'legacy' => false,
    ]);

    // 3. Belongs to categoryB but overrides to stationA (must be included in A, excluded from B)
    Product::factory()->create([
        'category_id' => $categoryB->id,
        'kitchen_station_id' => $stationA->id,
        'type' => ProductType::Manufactured,
        'legacy' => false,
    ]);

    // 4. Inherits stationB from categoryB
    Product::factory()->create([
        'category_id' => $categoryB->id,
        'kitchen_station_id' => null,
        'type' => ProductType::Manufactured,
        'legacy' => false,
    ]);

    // 5. Raw material assigned to station A (must be excluded)
    Product::factory()->create([
        'category_id' => $categoryA->id,
        'kitchen_station_id' => $stationA->id,
        'type' => ProductType::RawMaterial,
        'legacy' => false,
    ]);

    // 6. Legacy product inheriting station A from category A (must be excluded)
    Product::factory()->create([
        'category_id' => $categoryA->id,
        'kitchen_station_id' => null,
        'type' => ProductType::Manufactured,
        'legacy' => true,
    ]);

    expect($stationA->resolvedProductsCount())->toBe(2);
    expect($stationB->resolvedProductsCount())->toBe(2);

    // Verify scopeWithResolvedProductsCount
    /** @var KitchenStation $loadedStationA */
    $loadedStationA = KitchenStation::query()->withResolvedProductsCount()->find($stationA->id);
    expect($loadedStationA->resolved_products_count)->toBe(2);
    expect($loadedStationA->resolvedProductsCount())->toBe(2);

    /** @var KitchenStation $loadedStationB */
    $loadedStationB = KitchenStation::query()->withResolvedProductsCount()->find($stationB->id);
    expect($loadedStationB->resolved_products_count)->toBe(2);
    expect($loadedStationB->resolvedProductsCount())->toBe(2);
});

it('forbids associate and dissociate actions for users without update_kitchen_stations permission', function () {
    $cashier = User::factory()->cashier()->create();
    actingAs($cashier);

    $station = KitchenStation::factory()->create();
    $category = Category::factory()->create(['kitchen_station_id' => $station->id]);
    $product = Product::factory()->create([
        'kitchen_station_id' => $station->id,
        'type' => ProductType::Manufactured,
        'legacy' => false,
    ]);

    livewire(CategoriesRelationManager::class, [
        'ownerRecord' => $station,
        'pageClass' => EditKitchenStation::class,
    ])
        // Filament 5 refuses to mount relation managers the user cannot view.
        ->assertForbidden();

    livewire(ProductsRelationManager::class, [
        'ownerRecord' => $station,
        'pageClass' => EditKitchenStation::class,
    ])
        ->assertForbidden();
});
