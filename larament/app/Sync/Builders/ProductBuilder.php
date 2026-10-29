<?php

namespace App\Sync\Builders;

use App\Models\Product;
use App\Sync\Builders\Concerns\IgnoresKey;
use App\Sync\DocumentType;
use App\Sync\Payload;

class ProductBuilder implements DocumentBuilder
{
    use IgnoresKey;

    public function type(): DocumentType
    {
        return DocumentType::Product;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(string $uuid): ?array
    {
        /** @var Product|null $product */
        $product = Product::query()
            ->with('category')
            ->where('uuid', $uuid)
            ->first();

        if (! $product) {
            return null;
        }

        /** @var \App\Models\Category|null $category */
        $category = $product->category;

        return [
            'name' => (string) $product->name,
            'product_ref' => $product->product_ref,
            'category_uuid' => $category?->getAttribute('uuid'),
            'type' => $product->type->value,
            'unit' => $product->unit,
            'price' => Payload::money($product->price),
            'cost' => Payload::money($product->cost),
            'legacy' => (bool) $product->legacy,
        ];
    }
}
