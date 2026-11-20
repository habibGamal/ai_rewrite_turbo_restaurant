<?php

namespace App\Services\Products;

use App\Enums\ProductType;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Product search for the cashier screen when the catalogue is too big to
 * send whole (instant sale with thousands of SKUs). Answers the same
 * products the category tree would: sellable, active, not legacy, and not
 * in a category blocked for the order type. Variant SKUs are returned as
 * plain products, so a search for "قميص احمر" finds the exact SKU.
 */
class PosProductSearchService
{
    /**
     * Above this many sellable products the cashier screen searches on the
     * server (config `pos.remote_catalog_threshold`).
     */
    public const REMOTE_THRESHOLD = 1500;

    public const LIMIT = 60;

    public function sellableCount(): int
    {
        return $this->sellable()->count();
    }

    public function usesRemoteCatalog(): bool
    {
        return $this->sellableCount() > (int) config('pos.remote_catalog_threshold', self::REMOTE_THRESHOLD);
    }

    /**
     * @return Collection<int, Product>
     */
    public function search(string $orderType, ?string $term = null, ?int $categoryId = null, int $limit = self::LIMIT): Collection
    {
        $term = trim((string) $term);

        return $this->sellable()
            ->tap(fn (Builder $query) => $this->notBlockedFor($query, $orderType))
            ->when($categoryId !== null, fn (Builder $query) => $query->whereHas(
                'category',
                fn (Builder $category) => $category->whereKey($categoryId)->orWhere('parent_id', $categoryId),
            ))
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $match) use ($term): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

                $match->where('name', 'like', $like)
                    ->orWhere('barcode', $term)
                    ->orWhere('sku', $term)
                    ->orWhereHas('barcodes', fn (Builder $barcodes) => $barcodes->where('barcode', $term));
            }))
            ->orderBy('name')
            ->limit(min(max($limit, 1), 200))
            ->get();
    }

    /**
     * The given products, for restoring a held sale or a scanned item.
     * Products not sellable on the order type are left out.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Product>
     */
    public function byIds(string $orderType, array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        return $this->sellable()
            ->tap(fn (Builder $query) => $this->notBlockedFor($query, $orderType))
            ->whereKey($ids)
            ->get();
    }

    /**
     * @return Builder<Product>
     */
    private function sellable(): Builder
    {
        return Product::query()
            ->whereNot('type', ProductType::RawMaterial)
            ->where('legacy', false)
            ->where('is_active', true);
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function notBlockedFor(Builder $query, string $orderType): void
    {
        $blocked = fn (Builder $types) => $types->where('code', $orderType);

        $query
            ->whereDoesntHave('category.blockedOrderTypes', $blocked)
            ->whereDoesntHave('category.parent.blockedOrderTypes', $blocked);
    }
}
