<?php

use App\Models\Product;
use App\Models\ProductComponent;
use App\Sync\Builders\RecipeBuilder;
use Illuminate\Support\Str;

it('builds a recipe snapshot for a product with components', function () {
    pairPlatform();

    $product = Product::factory()->create();
    $component1 = Product::factory()->create();
    $component2 = Product::factory()->create();

    ProductComponent::create([
        'product_id' => $product->id,
        'component_id' => $component1->id,
        'quantity' => 1.5,
    ]);

    ProductComponent::create([
        'product_id' => $product->id,
        'component_id' => $component2->id,
        'quantity' => 0.25,
    ]);

    $data = app(RecipeBuilder::class)->build($product->uuid);

    expect($data)->toEqual([
        'product_uuid' => $product->uuid,
        'components' => [
            [
                'component_uuid' => $component1->uuid,
                'quantity' => '1.500',
            ],
            [
                'component_uuid' => $component2->uuid,
                'quantity' => '0.250',
            ],
        ],
    ]);
});

it('returns null for an unknown product recipe uuid', function () {
    expect(app(RecipeBuilder::class)->build((string) Str::uuid()))->toBeNull();
});
