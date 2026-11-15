<?php

use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductGroup;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Services\Products\ProductVariantService;
use App\Sync\Builders\ProductBuilder;
use Illuminate\Support\Str;

it('builds a product snapshot', function () {
    pairPlatform();

    $category = Category::factory()->create();

    $product = Product::factory()->create([
        'name' => 'Classic Burger',
        'product_ref' => 'BGR-01',
        'category_id' => $category->id,
        'type' => ProductType::Manufactured,
        'unit' => 'piece',
        'price' => 120.00,
        'cost' => 45.00,
        'legacy' => false,
    ]);

    $data = app(ProductBuilder::class)->build($product->uuid);

    expect($data)->toEqual([
        'name' => 'Classic Burger',
        'product_ref' => 'BGR-01',
        'category_uuid' => $category->uuid,
        'type' => 'manufactured',
        'unit' => 'piece',
        'price' => '120.00',
        'cost' => '45.00',
        'legacy' => false,
        'product_group_uuid' => null,
        'sku' => null,
        'variant_label' => null,
        'has_custom_price' => false,
        'is_active' => true,
        'option_value_uuids' => [],
        'barcodes' => [],
    ]);
});

it('includes all barcodes with the primary one first', function () {
    $product = Product::factory()->create(['barcode' => '6221000000017']);
    ProductBarcode::create(['product_id' => $product->id, 'barcode' => '6221000000024', 'pack_quantity' => 12]);
    ProductBarcode::create(['product_id' => $product->id, 'barcode' => 'SHOP-1']);

    $data = app(ProductBuilder::class)->build($product->uuid);

    expect($data['barcodes'])->toBe([
        ['barcode' => '6221000000017', 'pack_quantity' => '1.000', 'is_primary' => true],
        ['barcode' => '6221000000024', 'pack_quantity' => '12.000', 'is_primary' => false],
        ['barcode' => 'SHOP-1', 'pack_quantity' => '1.000', 'is_primary' => false],
    ]);
});

it('includes variant fields and option values ordered by option position', function () {
    $group = ProductGroup::create([
        'name' => 'Shirt',
        'category_id' => Category::factory()->create()->id,
        'sku_prefix' => 'SHT',
        'base_price' => 200,
        'base_cost' => 90,
    ]);
    // Created out of position order on purpose.
    $color = ProductOption::create(['product_group_id' => $group->id, 'name' => 'Color', 'position' => 1]);
    $size = ProductOption::create(['product_group_id' => $group->id, 'name' => 'Size', 'position' => 0]);
    $black = ProductOptionValue::create(['product_option_id' => $color->id, 'value' => 'Black', 'code' => 'BLK']);
    $medium = ProductOptionValue::create(['product_option_id' => $size->id, 'value' => 'M']);

    $sku = app(ProductVariantService::class)->generate($group->fresh())->first();
    app(ProductVariantService::class)->setVariantPrice($sku, 250);

    $data = app(ProductBuilder::class)->build($sku->uuid);

    expect($data)
        ->product_group_uuid->toBe($group->uuid)
        ->sku->toBe('SHT-M-BLK')
        ->variant_label->toBe('M / Black')
        ->has_custom_price->toBeTrue()
        ->is_active->toBeTrue()
        ->price->toBe('250.00')
        ->option_value_uuids->toBe([$medium->uuid, $black->uuid]);
});

it('returns null for an unknown product uuid', function () {
    expect(app(ProductBuilder::class)->build((string) Str::uuid()))->toBeNull();
});
