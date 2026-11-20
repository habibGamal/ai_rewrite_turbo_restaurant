<?php

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\ProductGroup;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\Warehouse;
use App\Services\Products\ProductVariantService;

// Helpers for the variant grid, stock matrix and label tests.

/**
 * @param  array<string, array<int, string>>  $axes  option name => values
 */
function gridGroup(array $axes): ProductGroup
{
    $group = ProductGroup::create([
        'name' => 'قميص',
        'category_id' => Category::factory()->create()->id,
        'sku_prefix' => 'sh',
        'base_price' => 200,
        'base_cost' => 90,
    ]);

    $position = 0;
    foreach ($axes as $name => $values) {
        $option = ProductOption::create(['product_group_id' => $group->id, 'name' => $name, 'position' => $position++]);
        foreach ($values as $i => $value) {
            ProductOptionValue::create(['product_option_id' => $option->id, 'value' => $value, 'position' => $i]);
        }
    }

    app(ProductVariantService::class)->generate($group->fresh());

    return $group->fresh();
}

function gridSku(ProductGroup $group, string $label): int
{
    return (int) $group->variants()->where('variant_label', $label)->value('id');
}

function setStock(int $productId, float $quantity): void
{
    InventoryItem::query()->updateOrCreate(
        ['product_id' => $productId, 'warehouse_id' => Warehouse::default()->id],
        ['quantity' => $quantity],
    );
}
