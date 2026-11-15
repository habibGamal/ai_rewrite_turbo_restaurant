<?php

use App\Models\Category;
use App\Models\KitchenStation;
use App\Models\OrderTypeRow;
use App\Models\Product;
use App\Models\Warehouse;
use App\Modules\Restaurant\Services\KitchenRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('allows only two levels', function () {
    $main = Category::factory()->create(['name' => 'مشروبات']);
    $sub = Category::factory()->create([
        'name' => 'مشروبات ساخنة',
        'parent_id' => $main->id,
    ]);

    expect(fn () => Category::factory()->create(['parent_id' => $sub->id]))
        ->toThrow(ValidationException::class);

    $other = Category::factory()->create(['name' => 'حلويات']);

    expect(fn () => $main->update(['parent_id' => $other->id]))
        ->toThrow(ValidationException::class);
});

it('keeps existing categories as main categories', function () {
    $category = Category::factory()->create(['name' => 'مشاوي']);

    expect($category->parent_id)->toBeNull()
        ->and($category->mainCategory()->is($category))->toBeTrue()
        ->and($category->children)->toHaveCount(0)
        ->and(Schema::hasColumn('categories', 'parent_id'))->toBeTrue()
        ->and(Schema::hasTable('category_blocked_order_types'))->toBeTrue();
});

it('inherits blocks from the main category', function () {
    $desserts = Category::factory()->create(['name' => 'حلويات']);
    $cakes = Category::factory()->create([
        'name' => 'كيك',
        'parent_id' => $desserts->id,
    ]);
    $takeaway = OrderTypeRow::query()->where('code', 'takeaway')->firstOrFail();

    $desserts->blockedOrderTypes()->attach($takeaway->id);

    expect($cakes->blockedOrderTypeIds())->toContain($takeaway->id)
        ->and($cakes->blockedOrderTypeCodes())->toContain('takeaway')
        ->and($desserts->blockedOrderTypeIds())->toContain($takeaway->id);
});

it('lets a sub-category add its own block', function () {
    $drinks = Category::factory()->create(['name' => 'مشروبات']);
    $hot = Category::factory()->create([
        'name' => 'مشروبات ساخنة',
        'parent_id' => $drinks->id,
    ]);
    $delivery = OrderTypeRow::query()->where('code', 'delivery')->firstOrFail();

    $hot->blockedOrderTypes()->attach($delivery->id);

    expect($hot->blockedOrderTypeIds())->toContain($delivery->id)
        ->and($hot->blockedOrderTypeCodes())->toContain('delivery')
        ->and($drinks->blockedOrderTypeIds())->not->toContain($delivery->id);
});

it('uses the parent kitchen station when the sub has none', function () {
    $station = KitchenStation::factory()->create(['name' => 'البار']);
    $main = Category::factory()->create([
        'name' => 'مشروبات',
        'kitchen_station_id' => $station->id,
    ]);
    $sub = Category::factory()->create([
        'name' => 'مشروبات ساخنة',
        'parent_id' => $main->id,
        'kitchen_station_id' => null,
    ]);
    $product = Product::factory()->create([
        'category_id' => $sub->id,
        'kitchen_station_id' => null,
    ]);

    expect($sub->effectiveKitchenStationId())->toBe($station->id)
        ->and(app(KitchenRoutingService::class)->stationId($product))->toBe($station->id);

    $own = KitchenStation::factory()->create(['name' => 'الحلويات']);
    $sub->update(['kitchen_station_id' => $own->id]);

    expect($sub->fresh()->effectiveKitchenStationId())->toBe($own->id);
});

it('routes: product override > main category > default', function () {
    $grill = Warehouse::factory()->create(['name' => 'الشواية']);
    $bar = Warehouse::factory()->create(['name' => 'البار']);
    $main = Category::factory()->create(['name' => 'مشاوي', 'warehouse_id' => $grill->id]);
    $sub = Category::factory()->create([
        'name' => 'دجاج',
        'parent_id' => $main->id,
        'warehouse_id' => $bar->id,
    ]);
    expect($sub->fresh()->warehouse_id)->toBeNull();

    $fromCategory = Product::factory()->create([
        'category_id' => $sub->id,
        'warehouse_id' => null,
    ]);
    $override = Product::factory()->create([
        'category_id' => $sub->id,
        'warehouse_id' => $bar->id,
    ]);
    $unrouted = Product::factory()->create([
        'category_id' => Category::factory()->create(['warehouse_id' => null])->id,
        'warehouse_id' => null,
    ]);

    expect($fromCategory->stockWarehouseId())->toBe($grill->id)
        ->and($override->stockWarehouseId())->toBe($bar->id)
        ->and($unrouted->stockWarehouseId())->toBe(Warehouse::default()->id);

    $bar->update(['is_active' => false]);
    expect($override->fresh()->stockWarehouseId())->toBe($grill->id);

    $grill->update(['is_active' => false]);
    expect($fromCategory->fresh()->stockWarehouseId())->toBe(Warehouse::default()->id)
        ->and($override->fresh()->stockWarehouseId())->toBe(Warehouse::default()->id);
});

it('reverses the warehouse routing columns', function () {
    $migration = require database_path('migrations/2026_11_10_210001_add_warehouse_routing_to_categories_and_products.php');

    try {
        $migration->down();

        expect(Schema::hasColumn('categories', 'warehouse_id'))->toBeFalse()
            ->and(Schema::hasColumn('products', 'warehouse_id'))->toBeFalse();
    } finally {
        if (! Schema::hasColumn('categories', 'warehouse_id')) {
            $migration->up();
        }
    }
});

it('reverses the category hierarchy migration', function () {
    $migration = require database_path('migrations/2026_11_10_200000_add_hierarchy_and_routing_to_categories.php');

    try {
        $migration->down();

        expect(Schema::hasColumn('categories', 'parent_id'))->toBeFalse()
            ->and(Schema::hasTable('category_blocked_order_types'))->toBeFalse();
    } finally {
        if (! Schema::hasColumn('categories', 'parent_id')) {
            $migration->up();
        }
    }
});
