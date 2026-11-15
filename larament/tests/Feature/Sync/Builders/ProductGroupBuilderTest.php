<?php

use App\Models\Category;
use App\Models\ProductGroup;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Sync\Builders\ProductGroupBuilder;
use App\Sync\DocumentType;
use Illuminate\Support\Str;

it('builds a product group snapshot with its options and values', function () {
    $category = Category::factory()->create();
    $group = ProductGroup::create([
        'name' => 'Basic T-shirt',
        'category_id' => $category->id,
        'brand' => 'Acme',
        'sku_prefix' => 'TSH',
        'base_price' => 150,
        'base_cost' => 80.5,
        'unit' => 'piece',
        'is_active' => true,
    ]);

    $color = ProductOption::create(['product_group_id' => $group->id, 'name' => 'Color', 'position' => 1]);
    $size = ProductOption::create(['product_group_id' => $group->id, 'name' => 'Size', 'position' => 0]);
    $white = ProductOptionValue::create(['product_option_id' => $color->id, 'value' => 'White', 'code' => 'WHT', 'color_hex' => '#ffffff', 'position' => 1]);
    $black = ProductOptionValue::create(['product_option_id' => $color->id, 'value' => 'Black', 'code' => 'BLK', 'color_hex' => '#000000', 'position' => 0]);
    $small = ProductOptionValue::create(['product_option_id' => $size->id, 'value' => 'S', 'position' => 0]);

    $builder = app(ProductGroupBuilder::class);

    expect($builder->type())->toBe(DocumentType::ProductGroup)
        ->and($builder->build($group->uuid))->toBe([
            'name' => 'Basic T-shirt',
            'category_uuid' => $category->uuid,
            'brand' => 'Acme',
            'sku_prefix' => 'TSH',
            'base_price' => '150.00',
            'base_cost' => '80.50',
            'unit' => 'piece',
            'is_active' => true,
            'options' => [
                [
                    'uuid' => $size->uuid,
                    'name' => 'Size',
                    'position' => 0,
                    'values' => [
                        ['uuid' => $small->uuid, 'value' => 'S', 'code' => null, 'color_hex' => null, 'position' => 0],
                    ],
                ],
                [
                    'uuid' => $color->uuid,
                    'name' => 'Color',
                    'position' => 1,
                    'values' => [
                        ['uuid' => $black->uuid, 'value' => 'Black', 'code' => 'BLK', 'color_hex' => '#000000', 'position' => 0],
                        ['uuid' => $white->uuid, 'value' => 'White', 'code' => 'WHT', 'color_hex' => '#ffffff', 'position' => 1],
                    ],
                ],
            ],
        ]);
});

it('returns null for an unknown product group uuid', function () {
    expect(app(ProductGroupBuilder::class)->build((string) Str::uuid()))->toBeNull();
});
