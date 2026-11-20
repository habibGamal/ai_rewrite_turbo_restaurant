<?php

namespace App\Sync\Builders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductGroup;
use App\Models\ProductOptionValue;
use App\Sync\Builders\Concerns\IgnoresKey;
use App\Sync\DocumentType;
use App\Sync\Payload;
use Illuminate\Support\Collection;

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
            ->with(['category', 'group', 'optionValues.option', 'barcodes'])
            ->where('uuid', $uuid)
            ->first();

        if (! $product) {
            return null;
        }

        /** @var Category|null $category */
        $category = $product->category;

        /** @var ProductGroup|null $group */
        $group = $product->group;

        /** @var Collection<int, ProductBarcode> $barcodes */
        $barcodes = $product->barcodes;

        return [
            'name' => (string) $product->name,
            'product_ref' => $product->product_ref,
            'category_uuid' => $category?->getAttribute('uuid'),
            'type' => $product->type->value,
            'unit' => $product->unit,
            'price' => Payload::money($product->price),
            'cost' => Payload::money($product->cost),
            'legacy' => (bool) $product->legacy,
            'product_group_uuid' => $group?->uuid,
            'sku' => $product->sku,
            'variant_label' => $product->variant_label,
            'has_custom_price' => (bool) $product->has_custom_price,
            'is_active' => (bool) $product->is_active,
            // Ordered like the variant label: by option axis position.
            'option_value_uuids' => $product->optionValues
                ->sortBy(fn (ProductOptionValue $value) => [$value->option->position ?? 0, $value->product_option_id])
                ->map(fn (ProductOptionValue $value) => (string) $value->uuid)
                ->values()
                ->all(),
            // Primary barcode first (it mirrors products.barcode), then extras in creation order.
            'barcodes' => $barcodes
                ->sortBy(fn (ProductBarcode $barcode) => [$barcode->is_primary ? 0 : 1, $barcode->id])
                ->map(fn (ProductBarcode $barcode) => [
                    'barcode' => (string) $barcode->barcode,
                    'pack_quantity' => Payload::qty($barcode->pack_quantity),
                    'is_primary' => (bool) $barcode->is_primary,
                ])
                ->values()
                ->all(),
        ];
    }
}
