<?php

use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Product;
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
    ]);
});

it('returns null for an unknown product uuid', function () {
    expect(app(ProductBuilder::class)->build((string) Str::uuid()))->toBeNull();
});
