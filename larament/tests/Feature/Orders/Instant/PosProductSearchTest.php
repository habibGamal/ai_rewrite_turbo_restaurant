<?php

use App\Enums\ProductType;
use App\Models\Category;
use App\Models\OrderTypeRow;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Services\Products\PosProductSearchService;
use Tests\Support\SafetyNet;

beforeEach(function () {
    SafetyNet::till();
    OrderTypeRow::query()->where('code', 'instant_sale')->update(['is_active' => true]);

    $this->clothes = Category::factory()->create(['name' => 'ملابس']);
    $this->shirts = Category::factory()->create(['name' => 'قمصان', 'parent_id' => $this->clothes->id]);
    $this->shirt = Product::factory()->create([
        'name' => 'قميص كتان أزرق', 'category_id' => $this->shirts->id, 'barcode' => '6221000000017',
        'type' => ProductType::Consumable, 'is_active' => true, 'legacy' => false,
    ]);
    $this->belt = Product::factory()->create([
        'name' => 'حزام جلد', 'category_id' => $this->clothes->id,
        'type' => ProductType::Consumable, 'is_active' => true, 'legacy' => false,
    ]);
});

function searchIds(array $params): array
{
    return collect(test()->getJson(route('pos.products.search', ['type' => 'instant_sale'] + $params))
        ->assertOk()
        ->json('products'))
        ->pluck('id')
        ->sort()
        ->values()
        ->all();
}

it('finds products by part of the name, barcode, extra barcode or sku', function () {
    ProductBarcode::query()->create(['product_id' => $this->belt->id, 'barcode' => '999111', 'pack_quantity' => 1]);
    $this->belt->update(['sku' => 'BELT-01']);

    expect(searchIds(['q' => 'كتان']))->toBe([$this->shirt->id])
        ->and(searchIds(['q' => '6221000000017']))->toBe([$this->shirt->id])
        ->and(searchIds(['q' => '999111']))->toBe([$this->belt->id])
        ->and(searchIds(['q' => 'BELT-01']))->toBe([$this->belt->id]);
});

it('lists a main category with its sub-categories', function () {
    expect(searchIds(['category_id' => $this->clothes->id]))->toBe(collect([$this->shirt->id, $this->belt->id])->sort()->values()->all())
        ->and(searchIds(['category_id' => $this->shirts->id]))->toBe([$this->shirt->id]);
});

it('leaves out raw materials, inactive products and categories blocked for the order type', function () {
    Product::factory()->create(['name' => 'قماش كتان', 'type' => ProductType::RawMaterial, 'category_id' => $this->shirts->id]);
    Product::factory()->create(['name' => 'قميص كتان قديم', 'is_active' => false, 'category_id' => $this->shirts->id, 'type' => ProductType::Consumable]);

    expect(searchIds(['q' => 'كتان']))->toBe([$this->shirt->id]);

    $this->clothes->blockedOrderTypes()->attach(OrderTypeRow::query()->where('code', 'instant_sale')->value('id'));

    expect(searchIds(['q' => 'كتان']))->toBe([])
        ->and($this->getJson(route('pos.products.index', ['type' => 'instant_sale', 'ids' => [$this->shirt->id]]))->json('products'))->toBe([]);
});

it('returns products by id for held sales and scans', function () {
    $products = $this->getJson(route('pos.products.index', ['type' => 'instant_sale', 'ids' => [$this->shirt->id, $this->belt->id]]))
        ->assertOk()
        ->json('products');

    expect(collect($products)->pluck('name')->sort()->values()->all())->toBe(['حزام جلد', 'قميص كتان أزرق']);
});

it('sends the full menu for a small catalogue and categories only above the threshold', function () {
    expect(app(PosProductSearchService::class)->usesRemoteCatalog())->toBeFalse();

    config(['pos.remote_catalog_threshold' => 1]);

    expect(app(PosProductSearchService::class)->usesRemoteCatalog())->toBeTrue();

    $tab = collect($this->get(route('orders.index'))->assertOk()->viewData('page')['props']['tabs'])
        ->firstWhere('code', 'instant_sale');

    expect($tab['instant']['remoteCatalog'])->toBeTrue()
        ->and(collect($tab['instant']['categories'])->flatMap(fn ($category) => $category['products'])->all())->toBe([]);
});
