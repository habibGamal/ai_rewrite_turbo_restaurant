<?php

namespace App\Services\Products;

use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use Illuminate\Support\Collection;

/**
 * Lays a product group's variants out on two of its option axes (size down,
 * colour across), with every other axis fixed by a filter. Used by the stock
 * matrix and the purchase quantity grid; a group may have any number of
 * axes, the grid shows two at a time.
 */
class ProductGroupGridService
{
    /**
     * The group's axes that have values, in their order.
     *
     * @return Collection<int, ProductOption>
     */
    public function axes(ProductGroup $group): Collection
    {
        $group->loadMissing('options.values');

        return $group->options
            ->filter(fn (ProductOption $option): bool => $option->values->isNotEmpty())
            ->values();
    }

    /**
     * @param  array<int, int|null>  $fixed  option id => value id for the axes not on the grid (null = any)
     * @return array{
     *     row: ProductOption|null,
     *     col: ProductOption|null,
     *     rows: Collection<int, ProductOptionValue>,
     *     cols: Collection<int, ProductOptionValue>,
     *     cells: array<int, array<int, Product|null>>
     * }
     */
    public function grid(ProductGroup $group, ?int $rowOptionId = null, ?int $colOptionId = null, array $fixed = []): array
    {
        $axes = $this->axes($group);
        $row = $axes->firstWhere('id', $rowOptionId) ?? $axes->first();
        $col = $axes->firstWhere('id', $colOptionId)
            ?? $axes->first(fn (ProductOption $option): bool => $option->id !== $row?->id);
        if ($col !== null && $row !== null && $col->id === $row->id) {
            $col = null;
        }

        $rows = $row?->values->values() ?? collect();
        $cols = $col?->values->values() ?? collect([null]);

        $variants = $this->variants($group);

        $cells = [];
        foreach ($rows as $rowValue) {
            foreach ($cols as $colValue) {
                $wanted = array_filter([
                    $rowValue->id,
                    $colValue?->id,
                    ...array_values(array_filter(
                        $fixed,
                        fn (?int $valueId, int $optionId): bool => $valueId !== null
                            && $optionId !== $row?->id
                            && $optionId !== $col?->id,
                        ARRAY_FILTER_USE_BOTH,
                    )),
                ]);

                $matches = $variants->filter(fn (array $variant): bool => array_diff($wanted, $variant['values']) === []);

                // Without a filter on another axis a cell would hold several
                // variants; only a single match is a cell.
                $cells[$rowValue->id][$colValue === null ? 0 : $colValue->id] = $matches->count() === 1 ? $matches->first()['product'] : null;
            }
        }

        return [
            'row' => $row,
            'col' => $col,
            'rows' => $rows,
            'cols' => $cols->filter()->values(),
            'cells' => $cells,
        ];
    }

    /**
     * Axes other than the two on the grid: the ones that need a filter.
     *
     * @return Collection<int, ProductOption>
     */
    public function otherAxes(ProductGroup $group, ?ProductOption $row, ?ProductOption $col): Collection
    {
        return $this->axes($group)
            ->reject(fn (ProductOption $option): bool => $option->id === $row?->id || $option->id === $col?->id)
            ->values();
    }

    /**
     * Stock per variant, summed over warehouses unless one is given.
     *
     * @return array<int, float>
     */
    public function stock(ProductGroup $group, ?int $warehouseId = null): array
    {
        return InventoryItem::query()
            ->whereIn('product_id', $group->variants()->select('id'))
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->selectRaw('product_id, SUM(quantity) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id')
            ->map(fn ($qty): float => (float) $qty)
            ->all();
    }

    /**
     * @return Collection<int, array{product: Product, values: list<int>}>
     */
    private function variants(ProductGroup $group): Collection
    {
        return $group->variants()
            ->with('optionValues:id')
            ->orderBy('name')
            ->get()
            ->map(fn (Product $product): array => [
                'product' => $product,
                'values' => array_values($product->optionValues->pluck('id')->map(fn ($id): int => (int) $id)->all()),
            ]);
    }
}
