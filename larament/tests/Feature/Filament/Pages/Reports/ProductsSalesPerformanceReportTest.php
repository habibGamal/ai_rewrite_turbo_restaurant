<?php

use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Filament\Pages\Reports\ProductsSalesPerformanceReport;
use App\Filament\Widgets\CategoryPerformanceWidget;
use App\Filament\Widgets\NoProductsSalesInPeriodWidget;
use App\Filament\Widgets\OrderTypePerformanceWidget;
use App\Filament\Widgets\ProductsSalesGroupedWidget;
use App\Filament\Widgets\ProductsSalesStatsWidget;
use App\Filament\Widgets\ProductsSalesTableWidget;
use App\Filament\Widgets\TopProductsByProfitWidget;
use App\Filament\Widgets\TopProductsBySalesWidget;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
});

// Page Rendering & Authorization
it('can render the products sales performance report page', function () {
    livewire(ProductsSalesPerformanceReport::class)
        ->assertSuccessful();
});

it('can access page with admin role', function () {
    livewire(ProductsSalesPerformanceReport::class)
        ->assertSuccessful();
});

it('can access page with viewer role', function () {
    $viewer = User::factory()->viewer()->create();
    actingAs($viewer);

    livewire(ProductsSalesPerformanceReport::class)
        ->assertSuccessful();
});

it('cannot access page with cashier role', function () {
    $cashier = User::factory()->cashier()->create();
    actingAs($cashier);

    livewire(ProductsSalesPerformanceReport::class)
        ->assertForbidden();
});

// Schema & Filters Tests
it('has filter form with period, shifts, and category components', function () {
    livewire(ProductsSalesPerformanceReport::class)
        ->assertSchemaExists('filtersForm')
        ->assertSchemaComponentExists('filterType', 'filtersForm')
        ->assertSchemaComponentExists('shifts', 'filtersForm')
        ->assertSchemaComponentExists('presetPeriod', 'filtersForm')
        ->assertSchemaComponentExists('startDate', 'filtersForm')
        ->assertSchemaComponentExists('endDate', 'filtersForm')
        ->assertSchemaComponentExists('mainCategoryId', 'filtersForm')
        ->assertSchemaComponentExists('subCategoryId', 'filtersForm');
});

it('defaults to period mode with last 30 days preset', function () {
    $report = livewire(ProductsSalesPerformanceReport::class);

    expect($report->instance()->filters)
        ->toHaveKey('filterType')
        ->and($report->instance()->filters['filterType'])->toBe('period')
        ->and($report->instance()->filters)->toHaveKey('presetPeriod')
        ->and($report->instance()->filters['presetPeriod'])->toBe('last_30_days');
});

it('can switch to shifts filter mode and set selected shifts', function () {
    $shifts = Shift::factory()->count(2)->closed()->create();

    $report = livewire(ProductsSalesPerformanceReport::class)
        ->set('filters.filterType', 'shifts')
        ->set('filters.shifts', $shifts->pluck('id')->toArray());

    expect($report->get('filters.filterType'))->toBe('shifts')
        ->and($report->get('filters.shifts'))->toBe($shifts->pluck('id')->toArray());
});

it('can set custom date range filter', function () {
    $startDate = now()->subDays(7)->startOfDay()->toDateString();
    $endDate = now()->endOfDay()->toDateString();

    $report = livewire(ProductsSalesPerformanceReport::class)
        ->set('filters.presetPeriod', 'custom')
        ->set('filters.startDate', $startDate)
        ->set('filters.endDate', $endDate);

    expect($report->get('filters.startDate'))->toBe($startDate)
        ->and($report->get('filters.endDate'))->toBe($endDate);
});

it('can filter by main category and sub category', function () {
    $mainCategory = Category::factory()->create(['name' => 'تصنيف رئيسي']);
    $subCategory = Category::factory()->create([
        'name' => 'تصنيف فرعي',
        'parent_id' => $mainCategory->id,
    ]);

    $report = livewire(ProductsSalesPerformanceReport::class)
        ->set('filters.mainCategoryId', $mainCategory->id)
        ->set('filters.subCategoryId', $subCategory->id);

    expect($report->get('filters.mainCategoryId'))->toBe($mainCategory->id)
        ->and($report->get('filters.subCategoryId'))->toBe($subCategory->id);
});

// Widgets Visibility Tests
it('shows no products sales widget when there are no completed orders', function () {
    $report = livewire(ProductsSalesPerformanceReport::class);

    expect($report->instance()->getWidgets())
        ->toContain(NoProductsSalesInPeriodWidget::class)
        ->toHaveCount(1);
});

it('shows no data widget when shifts mode is selected with empty shifts', function () {
    $report = livewire(ProductsSalesPerformanceReport::class)
        ->fillForm([
            'filters.filterType' => 'shifts',
            'filters.shifts' => [],
        ]);

    expect($report->instance()->getWidgets())
        ->toContain(NoProductsSalesInPeriodWidget::class)
        ->toHaveCount(1);
});

it('shows all performance widgets when there are completed orders in period', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'price' => 100,
        'cost' => 50,
        'type' => ProductType::Consumable,
    ]);

    $order = Order::factory()->create([
        'type' => 'dine_in',
        'status' => OrderStatus::COMPLETED,
        'created_at' => now(),
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'price' => $product->price,
        'cost' => $product->cost,
        'total' => $product->price * 2,
    ]);

    $report = livewire(ProductsSalesPerformanceReport::class);
    $widgets = $report->instance()->getWidgets();

    expect($widgets)
        ->toContain(ProductsSalesStatsWidget::class)
        ->toContain(TopProductsBySalesWidget::class)
        ->toContain(TopProductsByProfitWidget::class)
        ->toContain(OrderTypePerformanceWidget::class)
        ->toContain(CategoryPerformanceWidget::class)
        ->toContain(ProductsSalesGroupedWidget::class)
        ->toContain(ProductsSalesTableWidget::class)
        ->not->toContain(NoProductsSalesInPeriodWidget::class);
});

it('shows all performance widgets when selected shifts have completed orders', function () {
    $shift = Shift::factory()->closed()->create([
        'start_at' => now()->subDays(90),
        'end_at' => now()->subDays(89),
    ]);

    $category = Category::factory()->create();
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'price' => 100,
        'cost' => 50,
        'type' => ProductType::Consumable,
    ]);

    $order = Order::factory()->create([
        'shift_id' => $shift->id,
        'type' => 'dine_in',
        'status' => OrderStatus::COMPLETED,
        'created_at' => now()->subDays(90),
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'price' => $product->price,
        'cost' => $product->cost,
        'total' => $product->price * 2,
    ]);

    $report = livewire(ProductsSalesPerformanceReport::class)
        ->fillForm([
            'filters.filterType' => 'shifts',
            'filters.shifts' => [$shift->id],
        ]);

    $widgets = $report->instance()->getWidgets();

    expect($widgets)
        ->toContain(ProductsSalesStatsWidget::class)
        ->toContain(TopProductsBySalesWidget::class)
        ->toContain(TopProductsByProfitWidget::class)
        ->toContain(OrderTypePerformanceWidget::class)
        ->toContain(CategoryPerformanceWidget::class)
        ->toContain(ProductsSalesGroupedWidget::class)
        ->toContain(ProductsSalesTableWidget::class)
        ->not->toContain(NoProductsSalesInPeriodWidget::class);
});

it('does not show performance widgets for pending orders', function () {
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);

    $pendingOrder = Order::factory()->create([
        'type' => 'delivery',
        'status' => OrderStatus::PENDING,
        'created_at' => now(),
    ]);

    OrderItem::factory()->create([
        'order_id' => $pendingOrder->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'price' => $product->price,
        'cost' => $product->cost,
        'total' => $product->price * 2,
    ]);

    $report = livewire(ProductsSalesPerformanceReport::class);

    expect($report->instance()->getWidgets())
        ->toContain(NoProductsSalesInPeriodWidget::class)
        ->not->toContain(ProductsSalesStatsWidget::class);
});

it('does not show performance widgets for cancelled orders', function () {
    $product = Product::factory()->create(['price' => 100, 'cost' => 50]);

    $cancelledOrder = Order::factory()->create([
        'type' => 'delivery',
        'status' => OrderStatus::CANCELLED,
        'created_at' => now(),
    ]);

    OrderItem::factory()->create([
        'order_id' => $cancelledOrder->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'price' => $product->price,
        'cost' => $product->cost,
        'total' => $product->price * 2,
    ]);

    $report = livewire(ProductsSalesPerformanceReport::class);

    expect($report->instance()->getWidgets())
        ->toContain(NoProductsSalesInPeriodWidget::class)
        ->not->toContain(ProductsSalesStatsWidget::class);
});

// Page Metadata
it('has expected page metadata', function () {
    expect(ProductsSalesPerformanceReport::getNavigationLabel())->toBe('تقرير أداء المنتجات')
        ->and(ProductsSalesPerformanceReport::getNavigationGroup())->toBe('التقارير')
        ->and(ProductsSalesPerformanceReport::getNavigationSort())->toBe(4)
        ->and(ProductsSalesPerformanceReport::getNavigationIcon())->toBe('heroicon-o-chart-bar');

    $page = livewire(ProductsSalesPerformanceReport::class);

    expect($page->instance()->getTitle())->toBe('تقرير أداء المنتجات في المبيعات');
});

// Table Search and Sort Tests
it('can search categories by name in category performance table', function () {
    $category1 = Category::factory()->create(['name' => 'عصائر طازجة']);
    $category2 = Category::factory()->create(['name' => 'مشويات لحوم']);

    $product1 = Product::factory()->create([
        'category_id' => $category1->id,
        'price' => 50,
        'cost' => 20,
        'type' => ProductType::Consumable,
    ]);

    $product2 = Product::factory()->create([
        'category_id' => $category2->id,
        'price' => 150,
        'cost' => 70,
        'type' => ProductType::Consumable,
    ]);

    $order = Order::factory()->create([
        'type' => 'dine_in',
        'status' => OrderStatus::COMPLETED,
        'created_at' => now(),
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product1->id,
        'quantity' => 1,
        'price' => $product1->price,
        'cost' => $product1->cost,
        'total' => $product1->price,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product2->id,
        'quantity' => 1,
        'price' => $product2->price,
        'cost' => $product2->cost,
        'total' => $product2->price,
    ]);

    livewire(CategoryPerformanceWidget::class, [
        'pageFilters' => [
            'startDate' => now()->subDays(29)->startOfDay()->toDateString(),
            'endDate' => now()->endOfDay()->toDateString(),
        ],
    ])
        ->searchTable('عصائر')
        ->assertCanSeeTableRecords([$category1])
        ->assertCanNotSeeTableRecords([$category2]);
});

it('can search products by name in products sales table', function () {
    $category = Category::factory()->create(['name' => 'وجبات سريعة']);

    $product1 = Product::factory()->create([
        'name' => 'برجر دجاج سوبريم',
        'category_id' => $category->id,
        'price' => 100,
        'cost' => 50,
        'type' => ProductType::Consumable,
    ]);

    $product2 = Product::factory()->create([
        'name' => 'بيتزا مارجريتا حجم كبير',
        'category_id' => $category->id,
        'price' => 120,
        'cost' => 60,
        'type' => ProductType::Consumable,
    ]);

    $order = Order::factory()->create([
        'type' => 'dine_in',
        'status' => OrderStatus::COMPLETED,
        'created_at' => now(),
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product1->id,
        'quantity' => 1,
        'price' => $product1->price,
        'cost' => $product1->cost,
        'total' => $product1->price,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product2->id,
        'quantity' => 1,
        'price' => $product2->price,
        'cost' => $product2->cost,
        'total' => $product2->price,
    ]);

    livewire(ProductsSalesTableWidget::class, [
        'pageFilters' => [
            'startDate' => now()->subDays(29)->startOfDay()->toDateString(),
            'endDate' => now()->endOfDay()->toDateString(),
        ],
    ])
        ->searchTable('برجر')
        ->assertCanSeeTableRecords([$product1])
        ->assertCanNotSeeTableRecords([$product2]);
});

it('can search products by category name in products sales table', function () {
    $category1 = Category::factory()->create(['name' => 'مأكولات بحرية']);
    $category2 = Category::factory()->create(['name' => 'حلويات شرقية']);

    $product1 = Product::factory()->create([
        'name' => 'جمبري مقلي',
        'category_id' => $category1->id,
        'price' => 200,
        'cost' => 100,
        'type' => ProductType::Consumable,
    ]);

    $product2 = Product::factory()->create([
        'name' => 'كنافة بالجبن',
        'category_id' => $category2->id,
        'price' => 80,
        'cost' => 40,
        'type' => ProductType::Consumable,
    ]);

    $order = Order::factory()->create([
        'type' => 'dine_in',
        'status' => OrderStatus::COMPLETED,
        'created_at' => now(),
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product1->id,
        'quantity' => 1,
        'price' => $product1->price,
        'cost' => $product1->cost,
        'total' => $product1->price,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product2->id,
        'quantity' => 1,
        'price' => $product2->price,
        'cost' => $product2->cost,
        'total' => $product2->price,
    ]);

    livewire(ProductsSalesTableWidget::class, [
        'pageFilters' => [
            'startDate' => now()->subDays(29)->startOfDay()->toDateString(),
            'endDate' => now()->endOfDay()->toDateString(),
        ],
    ])
        ->searchTable('بحرية')
        ->assertCanSeeTableRecords([$product1])
        ->assertCanNotSeeTableRecords([$product2]);
});

it('can sort products by name and category in products sales table', function () {
    $category = Category::factory()->create(['name' => 'أطباق رئيسية']);

    $product1 = Product::factory()->create([
        'name' => 'أرز بالخلطة',
        'category_id' => $category->id,
        'type' => ProductType::Consumable,
    ]);

    $product2 = Product::factory()->create([
        'name' => 'كباب مشوي',
        'category_id' => $category->id,
        'type' => ProductType::Consumable,
    ]);

    $order = Order::factory()->create([
        'type' => 'dine_in',
        'status' => OrderStatus::COMPLETED,
        'created_at' => now(),
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product1->id,
        'quantity' => 1,
        'price' => $product1->price,
        'cost' => $product1->cost,
        'total' => $product1->price,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product2->id,
        'quantity' => 1,
        'price' => $product2->price,
        'cost' => $product2->cost,
        'total' => $product2->price,
    ]);

    livewire(ProductsSalesTableWidget::class, [
        'pageFilters' => [
            'startDate' => now()->subDays(29)->startOfDay()->toDateString(),
            'endDate' => now()->endOfDay()->toDateString(),
        ],
    ])
        ->sortTable('name')
        ->assertCanSeeTableRecords([$product1, $product2], inOrder: true)
        ->sortTable('category_name')
        ->assertSuccessful();
});

it('can sort category performance table', function () {
    $category1 = Category::factory()->create(['name' => 'أول تصنيف']);
    $category2 = Category::factory()->create(['name' => 'ثاني تصنيف']);

    $product1 = Product::factory()->create([
        'category_id' => $category1->id,
        'type' => ProductType::Consumable,
    ]);

    $product2 = Product::factory()->create([
        'category_id' => $category2->id,
        'type' => ProductType::Consumable,
    ]);

    $order = Order::factory()->create([
        'type' => 'dine_in',
        'status' => OrderStatus::COMPLETED,
        'created_at' => now(),
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product1->id,
        'quantity' => 1,
        'price' => $product1->price,
        'cost' => $product1->cost,
        'total' => $product1->price,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product2->id,
        'quantity' => 1,
        'price' => $product2->price,
        'cost' => $product2->cost,
        'total' => $product2->price,
    ]);

    livewire(CategoryPerformanceWidget::class, [
        'pageFilters' => [
            'startDate' => now()->subDays(29)->startOfDay()->toDateString(),
            'endDate' => now()->endOfDay()->toDateString(),
        ],
    ])
        ->sortTable('category_name')
        ->assertSuccessful();
});

// ProductsSalesGroupedWidget Accordion & Lazy Loading Tests
it('can lazily load category details and toggle accordions in grouped widget', function () {
    $mainCat = Category::factory()->create(['name' => 'ركن الفطائر']);
    $product = Product::factory()->create([
        'name' => 'فطيرة دجاج',
        'category_id' => $mainCat->id,
        'type' => ProductType::Consumable,
    ]);

    $order = Order::factory()->create([
        'type' => 'dine_in',
        'status' => OrderStatus::COMPLETED,
        'created_at' => now(),
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'price' => 100,
        'cost' => 50,
        'total' => 200,
    ]);

    $widget = livewire(ProductsSalesGroupedWidget::class, [
        'pageFilters' => [
            'filterType' => 'period',
            'startDate' => now()->subDays(29)->startOfDay()->toDateString(),
            'endDate' => now()->endOfDay()->toDateString(),
        ],
    ]);

    // Initially collapsed: category title and totals are visible, but details are not expanded
    $widget->assertSuccessful()
        ->assertSee('ركن الفطائر')
        ->assertSee('200.00 ج.م')
        ->assertSet('expandedCategories', []);

    // Toggle category to expand and lazily load products
    $widget->call('toggleCategory', $mainCat->id)
        ->assertSet('expandedCategories', [$mainCat->id])
        ->assertSee('فطيرة دجاج');

    // Toggle again to collapse
    $widget->call('toggleCategory', $mainCat->id)
        ->assertSet('expandedCategories', []);

    // Expand all and collapse all
    $widget->call('expandAll')
        ->assertSet('expandedCategories', [$mainCat->id]);

    $widget->call('collapseAll')
        ->assertSet('expandedCategories', []);
});

it('can switch between main and secondary categories modes in category performance table', function () {
    $main = Category::factory()->create(['name' => 'ركن المشويات']);
    $sub = Category::factory()->create(['name' => 'كباب وكفتة', 'parent_id' => $main->id]);

    $product = Product::factory()->create([
        'name' => 'كفتة مشوية',
        'category_id' => $sub->id,
        'type' => ProductType::Consumable,
    ]);

    $order = Order::factory()->create([
        'type' => 'dine_in',
        'status' => OrderStatus::COMPLETED,
        'created_at' => now(),
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 150,
        'cost' => 60,
        'total' => 150,
    ]);

    $widget = livewire(CategoryPerformanceWidget::class, [
        'pageFilters' => [
            'startDate' => now()->subDays(29)->startOfDay()->toDateString(),
            'endDate' => now()->endOfDay()->toDateString(),
        ],
    ]);

    // Default mode: main
    $widget->assertSuccessful()
        ->assertSet('categoryMode', 'main')
        ->assertCanSeeTableRecords([$main])
        ->assertCanNotSeeTableRecords([$sub]);

    // Switch to secondary mode
    $widget->call('setCategoryMode', 'secondary')
        ->assertSet('categoryMode', 'secondary')
        ->assertCanSeeTableRecords([$sub])
        ->assertCanNotSeeTableRecords([$main]);

    // Switch back to main mode
    $widget->call('setCategoryMode', 'main')
        ->assertSet('categoryMode', 'main')
        ->assertCanSeeTableRecords([$main]);
});
