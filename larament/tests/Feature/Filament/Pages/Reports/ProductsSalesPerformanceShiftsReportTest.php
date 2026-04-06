<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ProductType;
use App\Enums\UserRole;
use App\Filament\Pages\Reports\ProductsSalesPerformanceShiftsReport;
use App\Filament\Widgets\CategoryPerformanceWidget;
use App\Filament\Widgets\NoProductsSalesInPeriodWidget;
use App\Filament\Widgets\OrderTypePerformanceWidget;
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
    actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
});

it('can render the products sales shifts report page', function () {
    livewire(ProductsSalesPerformanceShiftsReport::class)
        ->assertSuccessful();
});

it('can access page with viewer role', function () {
    $viewer = User::factory()->create(['role' => UserRole::VIEWER]);
    actingAs($viewer);

    livewire(ProductsSalesPerformanceShiftsReport::class)
        ->assertSuccessful();
});

it('cannot access page with cashier role', function () {
    $cashier = User::factory()->create(['role' => UserRole::CASHIER]);
    actingAs($cashier);

    livewire(ProductsSalesPerformanceShiftsReport::class)
        ->assertForbidden();
});

it('defaults to period mode with last 30 days preset', function () {
    $report = livewire(ProductsSalesPerformanceShiftsReport::class);

    expect($report->instance()->filters)
        ->toHaveKey('filterType')
        ->and($report->instance()->filters['filterType'])->toBe('period')
        ->and($report->instance()->filters)->toHaveKey('presetPeriod')
        ->and($report->instance()->filters['presetPeriod'])->toBe('last_30_days');
});

it('can switch to shifts filter mode and set selected shifts', function () {
    $shifts = Shift::factory()->count(2)->closed()->create();

    $report = livewire(ProductsSalesPerformanceShiftsReport::class)
        ->set('filters.filterType', 'shifts')
        ->set('filters.shifts', $shifts->pluck('id')->toArray());

    expect($report->get('filters.filterType'))->toBe('shifts')
        ->and($report->get('filters.shifts'))->toBe($shifts->pluck('id')->toArray());
});

it('shows no data widget when shifts mode is selected with empty shifts', function () {
    $report = livewire(ProductsSalesPerformanceShiftsReport::class)
        ->fillForm([
            'filters.filterType' => 'shifts',
            'filters.shifts' => [],
        ]);

    expect($report->instance()->getWidgets())
        ->toContain(NoProductsSalesInPeriodWidget::class)
        ->toHaveCount(1);
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
        'type' => OrderType::DINE_IN,
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

    $report = livewire(ProductsSalesPerformanceShiftsReport::class)
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
        ->toContain(ProductsSalesTableWidget::class)
        ->not->toContain(NoProductsSalesInPeriodWidget::class);
});

it('has expected page metadata', function () {
    expect(ProductsSalesPerformanceShiftsReport::getNavigationLabel())->toBe('تقرير أداء المنتجات بالشفتات')
        ->and(ProductsSalesPerformanceShiftsReport::getNavigationGroup())->toBe('التقارير')
        ->and(ProductsSalesPerformanceShiftsReport::getNavigationSort())->toBe(6)
        ->and(ProductsSalesPerformanceShiftsReport::getNavigationIcon())->toBe('heroicon-o-chart-bar');

    $page = livewire(ProductsSalesPerformanceShiftsReport::class);

    expect($page->instance()->getTitle())->toBe('تقرير أداء المنتجات في المبيعات بالشفتات');
});
