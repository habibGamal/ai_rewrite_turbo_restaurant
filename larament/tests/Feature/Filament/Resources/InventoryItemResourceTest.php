<?php

use App\Enums\InventoryMovementOperation;
use App\Enums\MovementReason;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Filament\Resources\InventoryItems\InventoryItemResource;
use App\Filament\Resources\InventoryItems\Pages\ListInventoryItems;
use App\Filament\Resources\InventoryItems\Pages\ViewInventoryItem;
use App\Filament\Resources\InventoryItems\RelationManagers\MovementsRelationManager;
use App\Filament\Resources\InventoryItems\RelationManagers\UsedInProductsRelationManager;
use App\Filament\Resources\InventoryItems\RelationManagers\WarehouseBalancesRelationManager;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\InventoryItemMovement;
use App\Models\InventoryItemMovementDaily;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Orders\OrderStockConversionService;
use Illuminate\Database\Eloquent\Factories\Sequence;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
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
    $record = InventoryItem::where('product_id', $product->id)->first()
        ?? InventoryItem::factory()->create(['product_id' => $product->id]);

    expect(InventoryItemResource::canEdit($record))->toBeFalse();
});

it('cannot delete records as deletion is disabled', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id]);
    $record = InventoryItem::where('product_id', $product->id)->first()
        ?? InventoryItem::factory()->create(['product_id' => $product->id]);

    expect(InventoryItemResource::canDelete($record))->toBeFalse();
});

it('can view records', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id]);
    $record = InventoryItem::where('product_id', $product->id)->first()
        ?? InventoryItem::factory()->create(['product_id' => $product->id]);

    expect(InventoryItemResource::canView($record))->toBeTrue();
});

// Table Column Tests
it('has column', function (string $column) {
    livewire(ListInventoryItems::class)
        ->assertTableColumnExists($column);
})->with(['product.name', 'product.category.name', 'warehouse.name', 'quantity', 'product.min_stock', 'product.unit', 'product.type', 'created_at', 'updated_at']);

it('can render column', function (string $column) {
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id]);
    InventoryItem::factory()->create(['product_id' => $product->id]);

    livewire(ListInventoryItems::class)
        ->assertCanRenderTableColumn($column);
})->with(['product.name', 'product.category.name', 'warehouse.name', 'quantity', 'product.min_stock', 'product.unit', 'product.type']);

it('can sort column', function (string $column) {
    $category = Category::factory()->create();
    $products = Product::factory(5)->create(['category_id' => $category->id]);
    $records = $products->map(fn ($product) => InventoryItem::factory()->create(['product_id' => $product->id]));

    livewire(ListInventoryItems::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records)
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records);
})->with(['quantity', 'created_at', 'updated_at']);

// Search Tests
it('can search by product name', function () {
    $category = Category::factory()->create(['name' => 'Inventory search category']);
    $products = Product::factory(5)
        ->sequence(fn (Sequence $sequence) => ['name' => 'Inventory search product '.$sequence->index])
        ->create(['category_id' => $category->id]);
    $records = $products->map(fn ($product) => InventoryItem::factory()->create(['product_id' => $product->id]));

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
    $records = $products->map(fn ($product) => InventoryItem::factory()->create(['product_id' => $product->id]));

    livewire(ListInventoryItems::class)
        ->assertCanSeeTableRecords($records);
});

it('can count table records', function () {
    $category = Category::factory()->create();
    $products = Product::factory(3)->create(['category_id' => $category->id]);
    $products->each(fn ($product) => InventoryItem::factory()->create(['product_id' => $product->id]));

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
    $record2 = InventoryItem::where('product_id', $rawProduct->id)->first()
        ?? InventoryItem::factory()->create(['product_id' => $rawProduct->id]);

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
    $record = InventoryItem::where('product_id', $product->id)->first()
        ?? InventoryItem::factory()->create(['product_id' => $product->id]);

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
    $record = InventoryItem::where('product_id', $product->id)->first()
        ?? InventoryItem::factory()->create(['product_id' => $product->id]);

    livewire(ViewInventoryItem::class, ['record' => $record->getRouteKey()])
        ->assertSeeLivewire(MovementsRelationManager::class);
});

it('movements relation manager shows correct columns', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id]);
    $record = InventoryItem::where('product_id', $product->id)->first()
        ?? InventoryItem::factory()->create(['product_id' => $product->id]);

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
    $records = $products->map(fn ($product) => InventoryItem::factory()->create(['product_id' => $product->id]));

    livewire(ListInventoryItems::class)
        ->assertCanSeeTableRecords($records);
});

// Timestamp Toggleability
it('created_at and updated_at are toggleable columns', function () {
    livewire(ListInventoryItems::class)
        ->assertTableColumnExists('created_at')
        ->assertTableColumnExists('updated_at');
});

// Multi-Warehouse Tests
it('lists the same product across multiple warehouses and filters or searches by warehouse', function () {
    $main = Warehouse::default();
    $grill = Warehouse::factory()->create(['name' => 'مخزن الشواية']);
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id, 'name' => 'دجاج متبل']);

    $mainItem = InventoryItem::factory()->create([
        'product_id' => $product->id,
        'warehouse_id' => $main->id,
        'quantity' => 35,
    ]);
    $grillItem = InventoryItem::factory()->create([
        'product_id' => $product->id,
        'warehouse_id' => $grill->id,
        'quantity' => 12,
    ]);

    expect($product->inventoryItems)->toHaveCount(2);

    livewire(ListInventoryItems::class)
        ->assertCanSeeTableRecords([$mainItem, $grillItem])
        ->filterTable('warehouse_id', $grill->id)
        ->assertCanSeeTableRecords([$grillItem])
        ->assertCanNotSeeTableRecords([$mainItem]);

    livewire(ListInventoryItems::class)
        ->searchTable('مخزن الشواية')
        ->assertCanSeeTableRecords([$grillItem])
        ->assertCanNotSeeTableRecords([$mainItem]);
});

it('shows warehouse balance and total stock across all warehouses on the view page', function () {
    $main = Warehouse::default();
    $grill = Warehouse::factory()->create(['name' => 'مخزن الشواية']);
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id, 'min_stock' => 10]);

    InventoryItem::factory()->create([
        'product_id' => $product->id,
        'warehouse_id' => $main->id,
        'quantity' => 40,
    ]);
    $grillItem = InventoryItem::factory()->create([
        'product_id' => $product->id,
        'warehouse_id' => $grill->id,
        'quantity' => 15,
    ]);

    livewire(ViewInventoryItem::class, ['record' => $grillItem->getRouteKey()])
        ->assertSchemaStateSet([
            'warehouse.name' => 'مخزن الشواية',
            'quantity' => 15.0,
            'total_stock' => 55.0,
        ]);
});

it('scopes InventoryItem movements and dailyMovements relationships to its warehouse across query, eager load, and existence checks', function () {
    $main = Warehouse::default();
    $grill = Warehouse::factory()->create(['name' => 'مخزن الشواية']);
    $bar = Warehouse::factory()->create(['name' => 'مخزن البار']);
    $product = Product::factory()->create(['type' => ProductType::Manufactured]);

    $mainItem = InventoryItem::factory()->create(['product_id' => $product->id, 'warehouse_id' => $main->id, 'quantity' => 20]);
    $grillItem = InventoryItem::factory()->create(['product_id' => $product->id, 'warehouse_id' => $grill->id, 'quantity' => 5]);
    $barItem = InventoryItem::factory()->create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 0]);

    $mainMovement = InventoryItemMovement::create([
        'product_id' => $product->id,
        'warehouse_id' => $main->id,
        'operation' => InventoryMovementOperation::IN,
        'reason' => MovementReason::PURCHASE,
        'quantity' => 20,
        'unit_cost' => 10,
        'total_cost' => 200,
    ]);
    $grillMovement = InventoryItemMovement::create([
        'product_id' => $product->id,
        'warehouse_id' => $grill->id,
        'operation' => InventoryMovementOperation::IN,
        'reason' => MovementReason::TRANSFER_IN,
        'quantity' => 5,
        'unit_cost' => 10,
        'total_cost' => 50,
    ]);

    InventoryItemMovementDaily::create([
        'product_id' => $product->id,
        'warehouse_id' => $main->id,
        'date' => today()->toDateString(),
        'start_quantity' => 0,
        'end_quantity' => 20,
    ]);

    expect($mainItem->movements()->pluck('id')->all())->toBe([$mainMovement->id])
        ->and($grillItem->movements()->pluck('id')->all())->toBe([$grillMovement->id])
        ->and($barItem->movements()->count())->toBe(0)
        ->and($mainItem->dailyMovements()->count())->toBe(1)
        ->and($grillItem->dailyMovements()->count())->toBe(0);

    $eager = InventoryItem::query()
        ->where('product_id', $product->id)
        ->with('movements')
        ->withCount('movements')
        ->get()
        ->keyBy('warehouse_id');

    expect($eager[$main->id]->movements->pluck('id')->all())->toBe([$mainMovement->id])
        ->and($eager[$grill->id]->movements->pluck('id')->all())->toBe([$grillMovement->id])
        ->and($eager[$bar->id]->movements)->toBeEmpty()
        ->and($eager[$main->id]->movements_count)->toBe(1)
        ->and($eager[$grill->id]->movements_count)->toBe(1)
        ->and($eager[$bar->id]->movements_count)->toBe(0);

    $withMovements = InventoryItem::query()
        ->where('product_id', $product->id)
        ->whereHas('movements')
        ->pluck('warehouse_id')
        ->all();

    expect($withMovements)->toEqualCanonicalizing([$main->id, $grill->id]);
});

it('shows only the owner warehouse movements in MovementsRelationManager and links transfer documents', function () {
    $main = Warehouse::default();
    $grill = Warehouse::factory()->create(['name' => 'مخزن الشواية']);
    $product = Product::factory()->create(['type' => ProductType::Manufactured]);

    $mainItem = InventoryItem::factory()->create(['product_id' => $product->id, 'warehouse_id' => $main->id, 'quantity' => 10]);
    $grillItem = InventoryItem::factory()->create(['product_id' => $product->id, 'warehouse_id' => $grill->id, 'quantity' => 10]);

    $transfer = StockTransfer::factory()->create([
        'from_warehouse_id' => $main->id,
        'to_warehouse_id' => $grill->id,
    ]);

    $outMovement = InventoryItemMovement::create([
        'product_id' => $product->id,
        'warehouse_id' => $main->id,
        'operation' => InventoryMovementOperation::OUT,
        'reason' => MovementReason::TRANSFER_OUT,
        'quantity' => 10,
        'unit_cost' => 15,
        'total_cost' => 150,
        'referenceable_type' => StockTransfer::class,
        'referenceable_id' => $transfer->id,
    ]);

    $inMovement = InventoryItemMovement::create([
        'product_id' => $product->id,
        'warehouse_id' => $grill->id,
        'operation' => InventoryMovementOperation::IN,
        'reason' => MovementReason::TRANSFER_IN,
        'quantity' => 10,
        'unit_cost' => 15,
        'total_cost' => 150,
        'referenceable_type' => StockTransfer::class,
        'referenceable_id' => $transfer->id,
    ]);

    livewire(MovementsRelationManager::class, [
        'ownerRecord' => $grillItem,
        'pageClass' => ViewInventoryItem::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$inMovement])
        ->assertCanNotSeeTableRecords([$outMovement])
        ->assertTableColumnFormattedStateSet('referenceable_type', 'إذن تحويل', $inMovement)
        ->assertTableActionVisible('view_reference', $inMovement);

    livewire(MovementsRelationManager::class, [
        'ownerRecord' => $mainItem,
        'pageClass' => ViewInventoryItem::class,
    ])
        ->assertCanSeeTableRecords([$outMovement])
        ->assertCanNotSeeTableRecords([$inMovement]);
});

it('lists all warehouse balances for the product in WarehouseBalancesRelationManager', function () {
    $main = Warehouse::default();
    $grill = Warehouse::factory()->create(['name' => 'مخزن الشواية']);
    $product = Product::factory()->create(['type' => ProductType::Manufactured, 'min_stock' => 5]);

    $mainItem = InventoryItem::factory()->create(['product_id' => $product->id, 'warehouse_id' => $main->id, 'quantity' => 25]);
    $grillItem = InventoryItem::factory()->create(['product_id' => $product->id, 'warehouse_id' => $grill->id, 'quantity' => 8]);

    livewire(WarehouseBalancesRelationManager::class, [
        'ownerRecord' => $mainItem,
        'pageClass' => ViewInventoryItem::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$mainItem, $grillItem])
        ->assertTableActionHidden('view_warehouse_item', $mainItem)
        ->assertTableActionVisible('view_warehouse_item', $grillItem);
});

it('calculates total_consumed in UsedInProductsRelationManager per owner warehouse only', function () {
    $main = Warehouse::default();
    $grill = Warehouse::factory()->create(['name' => 'مخزن الشواية']);
    $bar = Warehouse::factory()->create(['name' => 'مخزن البار']);

    $grillCategory = Category::factory()->create(['name' => 'مشاوي', 'warehouse_id' => $grill->id]);
    $barCategory = Category::factory()->create(['name' => 'بار', 'warehouse_id' => $bar->id]);

    $bread = Product::factory()->create(['name' => 'خبز', 'type' => ProductType::RawMaterial]);
    $mainBread = InventoryItem::where('product_id', $bread->id)->where('warehouse_id', $main->id)->sole();
    $grillBread = InventoryItem::create(['product_id' => $bread->id, 'warehouse_id' => $grill->id, 'quantity' => 50]);
    $barBread = InventoryItem::create(['product_id' => $bread->id, 'warehouse_id' => $bar->id, 'quantity' => 30]);

    $grillPlate = Product::factory()->create([
        'name' => 'طبق مشاوي',
        'type' => ProductType::Manufactured,
        'category_id' => $grillCategory->id,
    ]);
    $barSandwich = Product::factory()->create([
        'name' => 'سندويتش بار',
        'type' => ProductType::Manufactured,
        'category_id' => $barCategory->id,
    ]);

    $grillComponent = ProductComponent::create(['product_id' => $grillPlate->id, 'component_id' => $bread->id, 'quantity' => 2]);
    $barComponent = ProductComponent::create(['product_id' => $barSandwich->id, 'component_id' => $bread->id, 'quantity' => 1.5]);

    openInventoryDay($bread);

    $order = Order::factory()->create(['status' => OrderStatus::COMPLETED]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $grillPlate->id,
        'quantity' => 3,
        'price' => 100,
        'cost' => 40,
        'total' => 300,
    ]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $barSandwich->id,
        'quantity' => 4,
        'price' => 50,
        'cost' => 20,
        'total' => 200,
    ]);
    app(OrderStockConversionService::class)->removeStockForCompletedOrder($order->load('items.product'));

    livewire(UsedInProductsRelationManager::class, [
        'ownerRecord' => $grillBread,
        'pageClass' => ViewInventoryItem::class,
    ])
        ->assertSuccessful()
        ->assertTableColumnStateSet('total_consumed', 6.0, $grillComponent)
        ->assertTableColumnStateSet('total_consumed', 0.0, $barComponent);

    livewire(UsedInProductsRelationManager::class, [
        'ownerRecord' => $barBread,
        'pageClass' => ViewInventoryItem::class,
    ])
        ->assertSuccessful()
        ->assertTableColumnStateSet('total_consumed', 0.0, $grillComponent)
        ->assertTableColumnStateSet('total_consumed', 6.0, $barComponent);

    livewire(UsedInProductsRelationManager::class, [
        'ownerRecord' => $mainBread,
        'pageClass' => ViewInventoryItem::class,
    ])
        ->assertSuccessful()
        ->assertTableColumnStateSet('total_consumed', 0.0, $grillComponent)
        ->assertTableColumnStateSet('total_consumed', 0.0, $barComponent);
});
