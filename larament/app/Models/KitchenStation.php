<?php

namespace App\Models;

use App\Enums\ProductType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Branch-local kitchen station. Not synced (no HasSyncUuid).
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static Builder<KitchenStation> query()
 * @method static Builder<KitchenStation> active()
 * @method static Builder<KitchenStation> withResolvedProductsCount()
 */
class KitchenStation extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * @param  Builder<KitchenStation>  $query
     * @return Builder<KitchenStation>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    /**
     * Scope to add resolved_products_count subquery without N+1.
     * Excludes raw materials and legacy products.
     *
     * @param  Builder<KitchenStation>  $query
     * @return Builder<KitchenStation>
     */
    public function scopeWithResolvedProductsCount(Builder $query): Builder
    {
        if (empty($query->getQuery()->columns)) {
            $query->select('kitchen_stations.*');
        }

        return $query->selectSub(
            Product::query()
                ->selectRaw('count(*)')
                ->where(function (Builder $sellable) {
                    $sellable->whereNull('products.type')
                        ->orWhere('products.type', '!=', ProductType::RawMaterial->value);
                })
                ->where(function (Builder $nonLegacy) {
                    $nonLegacy->where('products.legacy', false)
                        ->orWhereNull('products.legacy');
                })
                ->where(function (Builder $q) {
                    $q->whereColumn('products.kitchen_station_id', 'kitchen_stations.id')
                        ->orWhere(function (Builder $q2) {
                            $q2->whereNull('products.kitchen_station_id')
                                ->whereExists(function ($sub) {
                                    $sub->selectRaw('1')
                                        ->from('categories')
                                        ->whereColumn('categories.id', 'products.category_id')
                                        ->whereColumn('categories.kitchen_station_id', 'kitchen_stations.id');
                                });
                        });
                }),
            'resolved_products_count'
        );
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Count products resolving to this station:
     * overrides directly on product, or inherited from this station's categories without override.
     * Excludes raw materials and legacy products.
     */
    public function resolvedProductsCount(): int
    {
        if ($this->getAttribute('resolved_products_count') !== null) {
            return (int) $this->getAttribute('resolved_products_count');
        }

        if (! $this->exists) {
            return 0;
        }

        return Product::query()
            ->where(function (Builder $sellable) {
                $sellable->whereNull('type')
                    ->orWhere('type', '!=', ProductType::RawMaterial->value);
            })
            ->where(function (Builder $nonLegacy) {
                $nonLegacy->where('legacy', false)
                    ->orWhereNull('legacy');
            })
            ->where(function (Builder $query) {
                $query->where('kitchen_station_id', $this->id)
                    ->orWhere(function (Builder $q) {
                        $q->whereNull('kitchen_station_id')
                            ->whereHas('category', fn (Builder $catQuery) => $catQuery->where('kitchen_station_id', $this->id));
                    });
            })
            ->count();
    }
}
