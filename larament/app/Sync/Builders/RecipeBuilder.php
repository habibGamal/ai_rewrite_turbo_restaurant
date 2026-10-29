<?php

namespace App\Sync\Builders;

use App\Models\Product;
use App\Models\ProductComponent;
use App\Sync\Builders\Concerns\IgnoresKey;
use App\Sync\DocumentType;
use App\Sync\Payload;

class RecipeBuilder implements DocumentBuilder
{
    use IgnoresKey;

    public function type(): DocumentType
    {
        return DocumentType::Recipe;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(string $uuid): ?array
    {
        /** @var Product|null $product */
        $product = Product::query()
            ->with('productComponents.component')
            ->where('uuid', $uuid)
            ->first();

        if (! $product) {
            return null;
        }

        $components = [];
        /** @var ProductComponent $pc */
        foreach ($product->productComponents as $pc) {
            $component = $pc->component;
            $components[] = [
                'component_uuid' => $component ? $component->getAttribute('uuid') : null,
                'quantity' => Payload::qty($pc->quantity),
            ];
        }

        return [
            'product_uuid' => (string) $product->getAttribute('uuid'),
            'components' => $components,
        ];
    }
}
