<?php

namespace App\Sync\Builders;

use App\Models\Category;
use App\Models\ProductGroup;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Sync\Builders\Concerns\IgnoresKey;
use App\Sync\DocumentType;
use App\Sync\Payload;

/**
 * A retail product group (style) with its option axes and values.
 * Its SKUs travel as ordinary product documents that point back here
 * through product_group_uuid and option_value_uuids.
 */
class ProductGroupBuilder implements DocumentBuilder
{
    use IgnoresKey;

    public function type(): DocumentType
    {
        return DocumentType::ProductGroup;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(string $uuid): ?array
    {
        /** @var ProductGroup|null $group */
        $group = ProductGroup::query()
            ->with(['category', 'options.values'])
            ->where('uuid', $uuid)
            ->first();

        if (! $group) {
            return null;
        }

        /** @var Category|null $category */
        $category = $group->category;

        return [
            'name' => (string) $group->name,
            'category_uuid' => $category?->getAttribute('uuid'),
            'brand' => $group->brand,
            'sku_prefix' => $group->sku_prefix,
            'base_price' => Payload::money($group->base_price),
            'base_cost' => Payload::money($group->base_cost),
            'unit' => (string) $group->unit,
            'is_active' => (bool) $group->is_active,
            'options' => $group->options->map(fn (ProductOption $option) => [
                'uuid' => (string) $option->uuid,
                'name' => (string) $option->name,
                'position' => (int) $option->position,
                'values' => $option->values->map(fn (ProductOptionValue $value) => [
                    'uuid' => (string) $value->uuid,
                    'value' => (string) $value->value,
                    'code' => $value->code,
                    'color_hex' => $value->color_hex,
                    'position' => (int) $value->position,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
