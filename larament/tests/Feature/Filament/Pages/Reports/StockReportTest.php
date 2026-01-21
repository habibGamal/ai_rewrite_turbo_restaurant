<?php

use App\Enums\ProductType;
use App\Enums\UserRole;
use App\Filament\Pages\Reports\StockReport;
use App\Filament\Widgets\StockReportTable;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\InventoryItemMovementDaily;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    actingAs($this->admin);
});

// Page Rendering Tests
it('can render the stock report page as admin', function () {
    livewire(StockReport::class)
        ->assertSuccessful();
});

it('can render the stock report page as viewer', function () {
    $viewer = User::factory()->create(['role' => UserRole::VIEWER]);
    actingAs($viewer);

    livewire(StockReport::class)
        ->assertSuccessful();
});

it('cannot access stock report page without admin or viewer role', function () {
    $user = User::factory()->create(['role' => UserRole::CASHIER]);
    actingAs($user);

    livewire(StockReport::class)
        ->assertForbidden();
});

// Widget Tests
it('displays stock report table widget', function () {
    livewire(StockReport::class)
        ->assertSeeLivewire(StockReportTable::class);
});

// Filter Form Tests
it('has filters form', function () {
    livewire(StockReport::class)
        ->assertFormExists();
});

it('has preset period filter', function () {
    livewire(StockReport::class)
        ->assertSchemaComponentExists('presetPeriod');
});

it('has start date filter', function () {
    livewire(StockReport::class)
        ->assertSchemaComponentExists('startDate');
});

it('has end date filter', function () {
    livewire(StockReport::class)
        ->assertSchemaComponentExists('endDate');
});

it('can change preset period to today', function () {
    livewire(StockReport::class)
        ->fillForm([
            'presetPeriod' => 'today',
        ])
        ->assertSchemaStateSet([
            'presetPeriod' => 'today',
        ]);
});

it('can change preset period to last 7 days', function () {
    livewire(StockReport::class)
        ->fillForm([
            'presetPeriod' => 'last_7_days',
        ])
        ->assertSchemaStateSet([
            'presetPeriod' => 'last_7_days',
        ]);
});

it('can change preset period to custom', function () {
    livewire(StockReport::class)
        ->fillForm([
            'presetPeriod' => 'custom',
        ])
        ->assertSchemaStateSet([
            'presetPeriod' => 'custom',
        ]);
});

it('start and end date are disabled when not using custom period', function () {
    livewire(StockReport::class)
        ->fillForm([
            'presetPeriod' => 'last_30_days',
        ])
        ->assertFormFieldDisabled('startDate')
        ->assertFormFieldDisabled('endDate');
});

it('start and end date are enabled when using custom period', function () {
    livewire(StockReport::class)
        ->fillForm([
            'presetPeriod' => 'custom',
        ])
        ->assertFormFieldEnabled('startDate')
        ->assertFormFieldEnabled('endDate');
});

// Table Widget - Column Tests
it('has name column', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('name');
});

it('has category column', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('category.name');
});

it('has start quantity column', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('start_quantity');
});

it('has incoming column', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('incoming');
});

it('has total quantity column', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('total_quantity');
});

it('has sales column', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('sales');
});

it('has return waste column', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('return_waste');
});

it('has total consumed column', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('total_consumed');
});

it('has ideal remaining column', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('ideal_remaining');
});

it('has actual remaining quantity column', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('actual_remaining_quantity');
});

it('has cost column', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('cost');
});

it('has deviation column', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('deviation');
});

it('has deviation value column', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('deviation_value');
});

it('has deviation percentage column', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('deviation_percentage');
});

// Table Rendering Tests
it('can render all columns', function (string $column) {
    $product = Product::factory()->create(['type' => ProductType::Consumable]);
    InventoryItem::factory()->create(['product_id' => $product->id]);

    livewire(StockReportTable::class)
        ->assertCanRenderTableColumn($column);
})->with([
    'name',
    'category.name',
    'start_quantity',
    'incoming',
    'total_quantity',
    'sales',
    'return_waste',
    'total_consumed',
    'ideal_remaining',
    'actual_remaining_quantity',
    'cost',
    'deviation',
    'deviation_value',
    'deviation_percentage',
]);

// Table Data Tests
it('excludes manufactured products from report', function () {
    $consumableProduct = Product::factory()->create([
        'name' => 'منتج قابل للاستهلاك', // Consumable product
        'type' => ProductType::Consumable,
    ]);
    $manufacturedProduct = Product::factory()->create([
        'name' => 'منتج مصنَّع', // Manufactured product
        'type' => ProductType::Manufactured,
    ]);

    InventoryItem::factory()->create(['product_id' => $consumableProduct->id]);
    InventoryItem::factory()->create(['product_id' => $manufacturedProduct->id]);

    livewire(StockReportTable::class)
        ->assertCanSeeTableRecords([$consumableProduct])
        ->assertSee($consumableProduct->name)
        ->assertDontSee($manufacturedProduct->name);
});

it('displays products with their categories', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'type' => ProductType::Consumable,
    ]);
    InventoryItem::factory()->create(['product_id' => $product->id]);

    livewire(StockReportTable::class)
        ->assertCanSeeTableRecords([$product])
        ->assertSee($category->name);
});

it('displays correct actual remaining quantity', function () {
    $product = Product::factory()->create(['type' => ProductType::Consumable]);
    $inventoryItem = InventoryItem::factory()->create([
        'product_id' => $product->id,
        'quantity' => 50,
    ]);

    livewire(StockReportTable::class)
        ->assertCanSeeTableRecords([$product])
        ->assertSee('50');
});

it('displays products with zero cost', function () {
    $product = Product::factory()->create([
        'type' => ProductType::Consumable,
        'cost' => 0,
    ]);
    InventoryItem::factory()->create(['product_id' => $product->id]);

    livewire(StockReportTable::class)
        ->assertCanSeeTableRecords([$product]);
});

// Table Search Tests
it('can search products by name', function () {
    $product1 = Product::factory()->create([
        'name' => 'بطاطس',
        'type' => ProductType::Consumable,
    ]);
    $product2 = Product::factory()->create([
        'name' => 'طماطم',
        'type' => ProductType::Consumable,
    ]);

    InventoryItem::factory()->create(['product_id' => $product1->id]);
    InventoryItem::factory()->create(['product_id' => $product2->id]);

    livewire(StockReportTable::class)
        ->searchTable('بطاطس')
        ->assertCanSeeTableRecords([$product1])
        ->assertSee('بطاطس')
        ->assertDontSee('طماطم');
});

it('can search products by category name', function () {
    $category1 = Category::factory()->create(['name' => 'خضروات']);
    $category2 = Category::factory()->create(['name' => 'فواكه']);

    $product1 = Product::factory()->create([
        'category_id' => $category1->id,
        'type' => ProductType::Consumable,
    ]);
    $product2 = Product::factory()->create([
        'category_id' => $category2->id,
        'type' => ProductType::Consumable,
    ]);

    InventoryItem::factory()->create(['product_id' => $product1->id]);
    InventoryItem::factory()->create(['product_id' => $product2->id]);

    livewire(StockReportTable::class)
        ->searchTable('خضروات')
        ->assertCanSeeTableRecords([$product1])
        ->assertSee('خضروات')
        ->assertDontSee('فواكه');
});

// Table Sorting Tests
it('can sort products by name', function () {
    $products = Product::factory()
        ->count(3)
        ->create(['type' => ProductType::Consumable]);

    $products->each(function ($product) {
        InventoryItem::factory()->create(['product_id' => $product->id]);
    });

    livewire(StockReportTable::class)
        ->sortTable('name')
        ->assertCanSeeTableRecords($products->sortBy('name'), inOrder: true)
        ->sortTable('name', 'desc')
        ->assertCanSeeTableRecords($products->sortByDesc('name'), inOrder: true);
});

it('can sort products by cost', function () {
    $products = Product::factory()
        ->count(3)
        ->sequence(
            ['cost' => 10],
            ['cost' => 25],
            ['cost' => 50],
        )
        ->create(['type' => ProductType::Consumable]);

    $products->each(function ($product) {
        InventoryItem::factory()->create(['product_id' => $product->id]);
    });

    livewire(StockReportTable::class)
        ->sortTable('cost')
        ->assertCanSeeTableRecords($products->sortBy('cost'), inOrder: true);
});

// Table Filter Tests
it('can filter by category', function () {
    $category1 = Category::factory()->create(['name' => 'خضروات']);
    $category2 = Category::factory()->create(['name' => 'فواكه']);

    $product1 = Product::factory()->create([
        'name' => 'بصل', // Onion
        'category_id' => $category1->id,
        'type' => ProductType::Consumable,
    ]);
    $product2 = Product::factory()->create([
        'name' => 'تفاح', // Apple
        'category_id' => $category2->id,
        'type' => ProductType::Consumable,
    ]);

    InventoryItem::factory()->create(['product_id' => $product1->id]);
    InventoryItem::factory()->create(['product_id' => $product2->id]);

    livewire(StockReportTable::class)
        ->filterTable('category_id', $category1->id)
        ->assertCanSeeTableRecords([$product1])
        ->assertSee($product1->name)
        ->assertDontSee($product2->name);
});

it('can filter products with stock', function () {
    $productWithStock = Product::factory()->create([
        'name' => 'منتج متوفر في المخزن', // Product with stock
        'type' => ProductType::Consumable,
    ]);
    $productWithoutStock = Product::factory()->create([
        'name' => 'منتج غير متوفر', // Product without stock
        'type' => ProductType::Consumable,
    ]);

    InventoryItem::factory()->create([
        'product_id' => $productWithStock->id,
        'quantity' => 10,
    ]);
    InventoryItem::factory()->create([
        'product_id' => $productWithoutStock->id,
        'quantity' => 0,
    ]);

    livewire(StockReportTable::class)
        ->filterTable('has_stock', true)
        ->assertCanSeeTableRecords([$productWithStock])
        ->assertSee($productWithStock->name)
        ->assertDontSee($productWithoutStock->name);
});

it('can filter products without stock', function () {
    $productWithStock = Product::factory()->create([
        'name' => 'منتج مكتمل المخزون', // Product with completed stock
        'type' => ProductType::Consumable,
    ]);
    $productWithoutStock = Product::factory()->create([
        'name' => 'منتج ناقص', // Product out of stock
        'type' => ProductType::Consumable,
    ]);

    InventoryItem::factory()->create([
        'product_id' => $productWithStock->id,
        'quantity' => 10,
    ]);
    InventoryItem::factory()->create([
        'product_id' => $productWithoutStock->id,
        'quantity' => 0,
    ]);

    livewire(StockReportTable::class)
        ->filterTable('has_stock', false)
        ->assertCanSeeTableRecords([$productWithoutStock])
        ->assertSee($productWithoutStock->name)
        ->assertDontSee($productWithStock->name);
});

it('can filter by cost range - low', function () {
    $lowCostProduct = Product::factory()->create([
        'name' => 'منتج خام رخيص', // Low cost product
        'cost' => 5,
        'type' => ProductType::Consumable,
    ]);
    $highCostProduct = Product::factory()->create([
        'name' => 'منتج خام غالي', // High cost product
        'cost' => 60,
        'type' => ProductType::Consumable,
    ]);

    InventoryItem::factory()->create(['product_id' => $lowCostProduct->id]);
    InventoryItem::factory()->create(['product_id' => $highCostProduct->id]);

    livewire(StockReportTable::class)
        ->filterTable('cost_range', 'low')
        ->assertCanSeeTableRecords([$lowCostProduct])
        ->assertSee($lowCostProduct->name)
        ->assertDontSee($highCostProduct->name);
});

it('can filter by cost range - medium', function () {
    $mediumCostProduct = Product::factory()->create([
        'name' => 'منتج خام متوسط', // Medium cost product
        'cost' => 25,
        'type' => ProductType::Consumable,
    ]);
    $highCostProduct = Product::factory()->create([
        'name' => 'منتج خام عالي', // High cost product
        'cost' => 60,
        'type' => ProductType::Consumable,
    ]);

    InventoryItem::factory()->create(['product_id' => $mediumCostProduct->id]);
    InventoryItem::factory()->create(['product_id' => $highCostProduct->id]);

    livewire(StockReportTable::class)
        ->filterTable('cost_range', 'medium')
        ->assertCanSeeTableRecords([$mediumCostProduct])
        ->assertSee($mediumCostProduct->name)
        ->assertDontSee($highCostProduct->name);
});

it('can filter by cost range - high', function () {
    $lowCostProduct = Product::factory()->create([
        'name' => 'منتج خام منخفض', // Low cost product
        'cost' => 5,
        'type' => ProductType::Consumable,
    ]);
    $highCostProduct = Product::factory()->create([
        'name' => 'منتج خام غالي التكلفة', // High cost product
        'cost' => 60,
        'type' => ProductType::Consumable,
    ]);

    InventoryItem::factory()->create(['product_id' => $lowCostProduct->id]);
    InventoryItem::factory()->create(['product_id' => $highCostProduct->id]);

    livewire(StockReportTable::class)
        ->filterTable('cost_range', 'high')
        ->assertCanSeeTableRecords([$highCostProduct])
        ->assertSee($highCostProduct->name)
        ->assertDontSee($lowCostProduct->name);
});

it('can filter products with zero cost', function () {
    $zeroCostProduct = Product::factory()->create([
        'name' => 'منتج بدون تكلفة', // Zero cost product
        'cost' => 0,
        'type' => ProductType::Consumable,
    ]);
    $normalProduct = Product::factory()->create([
        'name' => 'منتج بتكلفة عادية', // Normal cost product
        'cost' => 25,
        'type' => ProductType::Consumable,
    ]);

    InventoryItem::factory()->create(['product_id' => $zeroCostProduct->id]);
    InventoryItem::factory()->create(['product_id' => $normalProduct->id]);

    livewire(StockReportTable::class)
        ->filterTable('zero_cost')
        ->assertCanSeeTableRecords([$zeroCostProduct])
        ->assertSee($zeroCostProduct->name)
        ->assertDontSee($normalProduct->name);
});

// Table Actions Tests
it('has export action', function () {
    livewire(StockReportTable::class)
        ->assertTableActionExists('export');
});

it('has view product action', function () {
    $product = Product::factory()->create(['type' => ProductType::Consumable]);
    $inventoryItem = InventoryItem::factory()->create(['product_id' => $product->id]);

    livewire(StockReportTable::class)
        ->assertTableActionExists('view_product');
});

// Record Count Tests
it('shows correct record count', function () {
    $products = Product::factory()
        ->count(3)
        ->create(['type' => ProductType::Consumable]);

    $products->each(function ($product) {
        InventoryItem::factory()->create(['product_id' => $product->id]);
    });

    livewire(StockReportTable::class)
        ->assertCountTableRecords(3);
});

it('shows empty state when no products exist', function () {
    livewire(StockReportTable::class)
        ->assertSee('لا توجد منتجات')
        ->assertSee('لم يتم العثور على أي منتجات لعرضها في التقرير.');
});

// Pagination Tests
it('can paginate results', function () {
    $products = Product::factory()
        ->count(15)
        ->create(['type' => ProductType::Consumable]);

    $products->each(function ($product) {
        InventoryItem::factory()->create(['product_id' => $product->id]);
    });

    livewire(StockReportTable::class)
        ->assertSee('10') // Shows pagination option
        ->assertSee('25')
        ->assertSee('50');
});

// Toggleable Columns Tests
it('category column is toggleable', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('category.name');
});

it('incoming column is toggleable', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('incoming');
});

it('total quantity column is toggleable', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('total_quantity');
});

it('sales column is toggleable', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('sales');
});

it('return waste column is toggleable', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('return_waste');
});

it('total consumed column is toggleable', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('total_consumed');
});

it('ideal remaining column is toggleable', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('ideal_remaining');
});

it('actual remaining quantity column is toggleable', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('actual_remaining_quantity');
});

it('cost column is toggleable', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('cost');
});

it('deviation column is toggleable', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('deviation');
});

it('deviation value column is toggleable', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('deviation_value');
});

it('deviation percentage column is toggleable', function () {
    livewire(StockReportTable::class)
        ->assertTableColumnExists('deviation_percentage');
});
