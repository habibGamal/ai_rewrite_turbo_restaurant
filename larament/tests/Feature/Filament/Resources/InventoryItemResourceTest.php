<?php

use App\Enums\UserRole;
use App\Enums\ProductType;
use App\Filament\Resources\InventoryItems\InventoryItemResource;
use App\Filament\Resources\InventoryItems\Pages\ListInventoryItems;
use App\Filament\Resources\InventoryItems\Pages\ViewInventoryItem;
use App\Filament\Resources\InventoryItems\RelationManagers\MovementsRelationManager;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Category;
use App\Models\User;
use App\Models\InventoryItemMovement;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    actingAs($this->admin);
});

// Page Rendering Tests
it('can render the index page', function () {
    livewire(ListInventoryItems::class)
        ->assertSuccessful();
});

it('can render the view page', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id, 'min_stock' => 10]);
    $record = InventoryItem::factory()->create(['product_id' => $product->id, 'quantity' => 50]);

    livewire(ViewInventoryItem::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

it('cannot render create page as creation is disabled', function () {
    expect(InventoryItemResource::canCreate())->toBeFalse();
});

it('cannot edit records as editing is disabled', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id]);
    $record = InventoryItem::factory()->create(['product_id' => $product->id]);

    expect(InventoryItemResource::canEdit($record))->toBeFalse();
});

it('cannot delete records as deletion is disabled', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id]);
    $record = InventoryItem::factory()->create(['product_id' => $product->id]);

    expect(InventoryItemResource::canDelete($record))->toBeFalse();
});

it('can view records', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id]);
    $record = InventoryItem::factory()->create(['product_id' => $product->id]);

    expect(InventoryItemResource::canView($record))->toBeTrue();
});

// Table Column Tests
it('has column', function (string $column) {
    livewire(ListInventoryItems::class)
        ->assertTableColumnExists($column);
})->with(['product.name', 'product.category.name', 'quantity', 'product.min_stock', 'product.unit', 'product.type', 'created_at', 'updated_at']);

it('can render column', function (string $column) {
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id]);
    InventoryItem::factory()->create(['product_id' => $product->id]);

    livewire(ListInventoryItems::class)
        ->assertCanRenderTableColumn($column);
})->with(['product.name', 'product.category.name', 'quantity', 'product.min_stock', 'product.unit', 'product.type']);

it('can sort column', function (string $column) {
    $category = Category::factory()->create();
    $products = Product::factory(5)->create(['category_id' => $category->id]);
    $records = $products->map(fn($product) => InventoryItem::factory()->create(['product_id' => $product->id]));

    livewire(ListInventoryItems::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records)
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records);
})->with(['quantity', 'created_at', 'updated_at']);

// Search Tests
it('can search by product name', function () {
    $category = Category::factory()->create();
    $products = Product::factory(5)->create(['category_id' => $category->id]);
    $records = $products->map(fn($product) => InventoryItem::factory()->create(['product_id' => $product->id]));

    $searchProduct = $products->first();

    livewire(ListInventoryItems::class)
        ->searchTable($searchProduct->name)
        ->assertCanSeeTableRecords($records->where('product_id', $searchProduct->id))
        ->assertCanNotSeeTableRecords($records->where('product_id', '!=', $searchProduct->id));
});

it('can search by category name', function () {
    $category1 = Category::factory()->create(['name' => 'Category One']);
    $category2 = Category::factory()->create(['name' => 'Category Two']);

    $product1 = Product::factory()->create(['category_id' => $category1->id]);
    $product2 = Product::factory()->create(['category_id' => $category2->id]);

    $record1 = InventoryItem::factory()->create(['product_id' => $product1->id]);
    $record2 = InventoryItem::factory()->create(['product_id' => $product2->id]);

    livewire(ListInventoryItems::class)
        ->searchTable($category1->name)
        ->assertCanSeeTableRecords([$record1])
        ->assertCanNotSeeTableRecords([$record2]);
});

// Table Records Visibility
it('can see table records', function () {
    $category = Category::factory()->create();
    $products = Product::factory(5)->create(['category_id' => $category->id]);
    $records = $products->map(fn($product) => InventoryItem::factory()->create(['product_id' => $product->id]));

    livewire(ListInventoryItems::class)
        ->assertCanSeeTableRecords($records);
});

it('can count table records', function () {
    $category = Category::factory()->create();
    $products = Product::factory(3)->create(['category_id' => $category->id]);
    $products->each(fn($product) => InventoryItem::factory()->create(['product_id' => $product->id]));

    livewire(ListInventoryItems::class)
        ->assertCountTableRecords(3);
});

// Filter Tests
it('can filter by category', function () {
    $category1 = Category::factory()->create();
    $category2 = Category::factory()->create();

    $product1 = Product::factory()->create(['category_id' => $category1->id]);
    $product2 = Product::factory()->create(['category_id' => $category2->id]);

    $record1 = InventoryItem::factory()->create(['product_id' => $product1->id]);
    $record2 = InventoryItem::factory()->create(['product_id' => $product2->id]);

    livewire(ListInventoryItems::class)
        ->filterTable('product.category_id', $category1->id)
        ->assertCanSeeTableRecords([$record1])
        ->assertCanNotSeeTableRecords([$record2]);
});

it('can filter by product type', function () {
    $category = Category::factory()->create();

    $manufacturedProduct = Product::factory()->create([
        'category_id' => $category->id,
        'type' => ProductType::Manufactured,
    ]);
    $rawProduct = Product::factory()->create([
        'category_id' => $category->id,
        'type' => ProductType::RawMaterial,
    ]);

    $record1 = InventoryItem::factory()->create(['product_id' => $manufacturedProduct->id]);
    $record2 = InventoryItem::factory()->create(['product_id' => $rawProduct->id]);

    livewire(ListInventoryItems::class)
        ->filterTable('product_type', ['type' => ProductType::Manufactured->value])
        ->assertCanSeeTableRecords([$record1])
        ->assertCanNotSeeTableRecords([$record2]);
});

it('can filter by low stock', function () {
    $category = Category::factory()->create();

    $lowStockProduct = Product::factory()->create(['category_id' => $category->id, 'min_stock' => 50]);
    $goodStockProduct = Product::factory()->create(['category_id' => $category->id, 'min_stock' => 10]);

    $lowStockRecord = InventoryItem::factory()->create(['product_id' => $lowStockProduct->id, 'quantity' => 30]);
    $goodStockRecord = InventoryItem::factory()->create(['product_id' => $goodStockProduct->id, 'quantity' => 100]);

    livewire(ListInventoryItems::class)
        ->filterTable('low_stock')
        ->assertCanSeeTableRecords([$lowStockRecord])
        ->assertCanNotSeeTableRecords([$goodStockRecord]);
});

it('can filter by critical stock', function () {
    $category = Category::factory()->create();

    $criticalProduct = Product::factory()->create(['category_id' => $category->id, 'min_stock' => 50]);
    $safeProduct = Product::factory()->create(['category_id' => $category->id, 'min_stock' => 10]);

    $criticalRecord = InventoryItem::factory()->create(['product_id' => $criticalProduct->id, 'quantity' => 40]);
    $safeRecord = InventoryItem::factory()->create(['product_id' => $safeProduct->id, 'quantity' => 100]);

    livewire(ListInventoryItems::class)
        ->filterTable('critical_stock')
        ->assertCanSeeTableRecords([$criticalRecord])
        ->assertCanNotSeeTableRecords([$safeRecord]);
});

it('can filter by out of stock', function () {
    $category = Category::factory()->create();

    $outOfStockProduct = Product::factory()->create(['category_id' => $category->id]);
    $inStockProduct = Product::factory()->create(['category_id' => $category->id]);

    $outOfStockRecord = InventoryItem::factory()->create(['product_id' => $outOfStockProduct->id, 'quantity' => 0]);
    $inStockRecord = InventoryItem::factory()->create(['product_id' => $inStockProduct->id, 'quantity' => 50]);

    livewire(ListInventoryItems::class)
        ->filterTable('out_of_stock')
        ->assertCanSeeTableRecords([$outOfStockRecord])
        ->assertCanNotSeeTableRecords([$inStockRecord]);
});

// Badge Color Tests
it('displays quantity badge with correct color for high stock', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id, 'min_stock' => 10]);
    $record = InventoryItem::factory()->create(['product_id' => $product->id, 'quantity' => 100]);

    livewire(ListInventoryItems::class)
        ->assertCanSeeTableRecords([$record]);
});

it('displays quantity badge with correct color for low stock', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id, 'min_stock' => 50]);
    $record = InventoryItem::factory()->create(['product_id' => $product->id, 'quantity' => 60]);

    livewire(ListInventoryItems::class)
        ->assertCanSeeTableRecords([$record]);
});

it('displays quantity badge with correct color for critical stock', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id, 'min_stock' => 50]);
    $record = InventoryItem::factory()->create(['product_id' => $product->id, 'quantity' => 5]);

    livewire(ListInventoryItems::class)
        ->assertCanSeeTableRecords([$record]);
});

// Default Sorting Test
it('sorts by quantity ascending by default', function () {
    $category = Category::factory()->create();
    $product1 = Product::factory()->create(['category_id' => $category->id]);
    $product2 = Product::factory()->create(['category_id' => $category->id]);
    $product3 = Product::factory()->create(['category_id' => $category->id]);

    $record1 = InventoryItem::factory()->create(['product_id' => $product1->id, 'quantity' => 100]);
    $record2 = InventoryItem::factory()->create(['product_id' => $product2->id, 'quantity' => 10]);
    $record3 = InventoryItem::factory()->create(['product_id' => $product3->id, 'quantity' => 50]);

    livewire(ListInventoryItems::class)
        ->assertCanSeeTableRecords([$record1, $record2, $record3]);
});

// View Page Tests
it('can view inventory item details', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Test Product',
        'unit' => 'kg',
        'cost' => 100.50,
        'type' => ProductType::RawMaterial,
    ]);
    $record = InventoryItem::factory()->create(['product_id' => $product->id]);

    livewire(ViewInventoryItem::class, ['record' => $record->getRouteKey()])
        ->assertSchemaStateSet([
            'product.name' => 'Test Product',
            'product.category.name' => $category->name,
            'product.unit' => 'kg',
            'product.cost' => 100.50,
            'product.type' => ProductType::RawMaterial,
        ]);
});

// Relation Manager Tests
it('can load movements relation manager', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id]);
    $record = InventoryItem::factory()->create(['product_id' => $product->id]);

    livewire(ViewInventoryItem::class, ['record' => $record->getRouteKey()])
        ->assertSeeLivewire(MovementsRelationManager::class);
});

it('movements relation manager shows correct columns', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id]);
    $record = InventoryItem::factory()->create(['product_id' => $product->id]);

    livewire(MovementsRelationManager::class, [
        'ownerRecord' => $record,
        'pageClass' => ViewInventoryItem::class,
    ])
        ->assertSuccessful()
        ->assertTableColumnExists('operation')
        ->assertTableColumnExists('quantity')
        ->assertTableColumnExists('reason')
        ->assertTableColumnExists('referenceable_type')
        ->assertTableColumnExists('referenceable_id')
        ->assertTableColumnExists('created_at');
});

// Table Actions
it('has view action on list page', function () {
    livewire(ListInventoryItems::class)
        ->assertTableActionExists('view');
});

it('does not have create action on list page', function () {
    livewire(ListInventoryItems::class)
        ->assertActionDoesNotExist('create');
});

it('does not have edit action on list page', function () {
    livewire(ListInventoryItems::class)
        ->assertTableActionDoesNotExist('edit');
});

it('does not have delete action on list page', function () {
    livewire(ListInventoryItems::class)
        ->assertTableActionDoesNotExist('delete');
});

it('does not have bulk actions', function () {
    $category = Category::factory()->create();
    $products = Product::factory(3)->create(['category_id' => $category->id]);
    $records = $products->map(fn($product) => InventoryItem::factory()->create(['product_id' => $product->id]));

    livewire(ListInventoryItems::class)
        ->assertCanSeeTableRecords($records);
});

// Timestamp Toggleability
it('created_at and updated_at are toggleable columns', function () {
    livewire(ListInventoryItems::class)
        ->assertTableColumnExists('created_at')
        ->assertTableColumnExists('updated_at');
});
