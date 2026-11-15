<?php

namespace App\Services\Products;

use App\Enums\ProductType;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use Illuminate\Database\Eloquent\Collection;

/**
 * Builds the POS "group tiles" for retail variants: one tile per active
 * product group, carrying its option axes and its sellable SKUs with the
 * stock in the warehouse the POS deducts each SKU from.
 */
class PosVariantCatalogService
{
    /**
     * Active groups that have at least one sellable SKU, keyed by category id.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function tilesByCategory(): array
    {
        /** @var Collection<int, ProductGroup> $groups */
        $groups = ProductGroup::query()
            ->where('is_active', true)
            ->with([
                'options.values',
                'variants' => fn ($query) => $query
                    ->where('is_active', true)
                    ->where('legacy', false)
                    ->whereNot('type', ProductType::RawMaterial)
                    ->orderBy('id'),
                'variants.optionValues:id',
                'variants.warehouse',
                'variants.category.warehouse',
                'variants.category.parent.warehouse',
            ])
            ->orderBy('name')
            ->get();

        $skuIds = $groups->flatMap(fn (ProductGroup $group) => $group->variants->pluck('id'))->all();
        $stock = InventoryItem::query()
            ->whereIn('product_id', $skuIds)
            ->get(['product_id', 'warehouse_id', 'quantity'])
            ->mapWithKeys(fn (InventoryItem $item) => [$item->product_id.':'.$item->warehouse_id => (float) $item->quantity]);

        $tiles = [];
        foreach ($groups as $group) {
            if ($group->variants->isEmpty()) {
                continue;
            }

            $tiles[$group->category_id][] = $this->tile($group, $stock->all());
        }

        return $tiles;
    }

    /**
     * @param  array<string, float>  $stock  "product_id:warehouse_id" => quantity
     * @return array<string, mixed>
     */
    private function tile(ProductGroup $group, array $stock): array
    {
        $prices = $group->variants->map(fn (Product $sku) => (float) $sku->price);

        return [
            'id' => $group->id,
            'name' => $group->name,
            'image' => $group->image,
            'category_id' => $group->category_id,
            'min_price' => $prices->min(),
            'max_price' => $prices->max(),
            'options' => $group->options
                ->map(fn (ProductOption $option) => [
                    'id' => $option->id,
                    'name' => $option->name,
                    'values' => $option->values
                        ->map(fn (ProductOptionValue $value) => [
                            'id' => $value->id,
                            'value' => $value->value,
                            'color_hex' => $value->color_hex,
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
            'skus' => $group->variants
                ->map(fn (Product $sku) => [
                    'id' => $sku->id,
                    'category_id' => $sku->category_id,
                    'product_group_id' => $sku->product_group_id,
                    'name' => $sku->name,
                    'variant_label' => $sku->variant_label,
                    'price' => (float) $sku->price,
                    'type' => $sku->type->value,
                    'unit' => $sku->unit,
                    'legacy' => false,
                    'barcode' => $sku->barcode,
                    'option_value_ids' => $sku->optionValues->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                    'stock' => $stock[$sku->id.':'.$sku->stockWarehouseId()] ?? 0.0,
                ])
                ->values()
                ->all(),
        ];
    }
}
