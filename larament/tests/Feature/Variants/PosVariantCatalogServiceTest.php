<?php

use App\Enums\OptionalFeature;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\Shift;
use App\Models\User;
use App\Services\FeatureService;
use App\Services\Products\ProductLookupService;
use App\Services\Products\ProductVariantService;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A size x colour group with generated SKUs in the given category.
 */
function posVariantGroup(Category $category, array $attributes = []): ProductGroup
{
    $group = ProductGroup::create(array_merge([
        'name' => 'تيشيرت',
        'category_id' => $category->id,
        'sku_prefix' => 'tsh',
        'base_price' => 150,
        'base_cost' => 80,
    ], $attributes));

    $axes = ['المقاس' => ['S', 'M'], 'اللون' => ['أسود', 'أبيض']];
    $position = 0;
    foreach ($axes as $name => $values) {
        $option = ProductOption::create(['product_group_id' => $group->id, 'name' => $name, 'position' => $position++]);
        foreach ($values as $i => $value) {
            ProductOptionValue::create([
                'product_option_id' => $option->id,
                'value' => $value,
                'position' => $i,
                'color_hex' => $value === 'أسود' ? '#000000' : null,
            ]);
        }
    }

    app(ProductVariantService::class)->generate($group);

    return $group->fresh();
}

function posSku(ProductGroup $group, string $label): Product
{
    return $group->variants()->where('variant_label', $label)->firstOrFail();
}

/** @return array<string, mixed> the category node from the ManageOrder payload */
function posCategoryNode(mixed $test, Order $order, int $categoryId): array
{
    $response = $test->get(route('orders.manage', $order))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Orders/ManageOrder')->has('categories'));

    $categories = json_decode(json_encode($response->viewData('page')['props']['categories']), true);
    $node = collect($categories)->firstWhere('id', $categoryId);

    expect($node)->not->toBeNull();

    return $node;
}

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->shift = Shift::factory()->active()->create(['user_id' => $this->user->id]);
    $this->actingAs($this->user);

    $this->category = Category::factory()->create(['name' => 'ملابس']);
    $this->plain = Product::factory()->create([
        'category_id' => $this->category->id,
        'name' => 'شراب',
        'type' => ProductType::Consumable,
        'legacy' => false,
    ]);
    $this->retired = Product::factory()->create([
        'category_id' => $this->category->id,
        'name' => 'منتج متوقف',
        'type' => ProductType::Consumable,
        'legacy' => false,
        'is_active' => false,
    ]);

    $this->group = posVariantGroup($this->category);

    $this->order = Order::factory()->create([
        'shift_id' => $this->shift->id,
        'user_id' => $this->user->id,
        'status' => OrderStatus::PROCESSING,
        'type' => 'takeaway',
    ]);
});

it('sends one tile per group instead of SKU tiles when variants are on', function () {
    app(FeatureService::class)->enable(OptionalFeature::VARIANTS);

    $blackM = posSku($this->group, 'M / أسود');
    app(ProductVariantService::class)->setVariantPrice($blackM, 180);
    InventoryItem::updateOrCreate(
        ['product_id' => $blackM->id, 'warehouse_id' => $blackM->fresh()->stockWarehouseId()],
        ['quantity' => 7],
    );
    posSku($this->group, 'S / أبيض')->update(['is_active' => false]);

    $node = posCategoryNode($this, $this->order, $this->category->id);
    $productIds = collect($node['products'])->pluck('id');

    expect($productIds->all())->toBe([$this->plain->id])
        ->and($node['groups'])->toHaveCount(1);

    $tile = $node['groups'][0];
    expect($tile['id'])->toBe($this->group->id)
        ->and($tile['name'])->toBe('تيشيرت')
        ->and((float) $tile['min_price'])->toBe(150.0)
        ->and((float) $tile['max_price'])->toBe(180.0)
        ->and(collect($tile['options'])->pluck('name')->all())->toBe(['المقاس', 'اللون'])
        ->and(collect($tile['options'][1]['values'])->pluck('value')->all())->toBe(['أسود', 'أبيض'])
        ->and($tile['options'][1]['values'][0]['color_hex'])->toBe('#000000')
        ->and($tile['skus'])->toHaveCount(3)
        ->and(collect($tile['skus'])->pluck('variant_label')->all())->not->toContain('S / أبيض');

    $sku = collect($tile['skus'])->firstWhere('id', $blackM->id);
    $expectedValueIds = $blackM->optionValues()->pluck('product_option_values.id')->sort()->values()->all();
    expect($sku['name'])->toBe('تيشيرت - M / أسود')
        ->and((float) $sku['price'])->toBe(180.0)
        ->and((float) $sku['stock'])->toBe(7.0)
        ->and(collect($sku['option_value_ids'])->sort()->values()->all())->toBe($expectedValueIds)
        ->and($sku['unit'])->toBe('piece');
});

it('hides inactive groups and groups with no active SKU', function () {
    app(FeatureService::class)->enable(OptionalFeature::VARIANTS);

    $this->group->update(['is_active' => false]);
    $other = posVariantGroup($this->category, ['name' => 'بنطلون', 'sku_prefix' => 'pnt']);
    $other->variants()->update(['is_active' => false]);

    $node = posCategoryNode($this, $this->order, $this->category->id);

    expect($node['groups'])->toBe([]);
});

it('sends variant SKUs as plain products when variants are off', function () {
    $node = posCategoryNode($this, $this->order, $this->category->id);
    $productIds = collect($node['products'])->pluck('id');

    expect($node)->not->toHaveKey('groups')
        ->and($productIds)->toContain($this->plain->id)
        ->and($productIds)->not->toContain($this->retired->id)
        ->and($productIds->intersect($this->group->variants()->pluck('id'))->count())->toBe(4);
});

it('adds the exact SKU from a scanned barcode and skips inactive SKUs', function () {
    app(ProductVariantService::class)->assignMissingBarcodes($this->group);
    $sku = posSku($this->group, 'M / أبيض');

    $this->getJson(route('products.lookup', ['code' => $sku->barcode]))
        ->assertOk()
        ->assertJson(['found' => true, 'product_id' => $sku->id, 'quantity' => 1]);

    $sku->update(['is_active' => false]);

    $this->getJson(route('products.lookup', ['code' => $sku->barcode]))->assertNotFound();
    expect(app(ProductLookupService::class)->lookup($sku->barcode))->toBeNull();
});
