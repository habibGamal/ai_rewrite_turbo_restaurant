<?php

namespace App\Models;

use App\Models\Concerns\HasSyncUuid;
use App\Services\Products\ProductVariantService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A retail "style" (e.g. Basic T-shirt) whose variants are products rows.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property int $category_id
 * @property string|null $brand
 * @property string|null $sku_prefix
 * @property string|float $base_price
 * @property string|float $base_cost
 * @property string $unit
 * @property string|null $image
 * @property bool $is_active
 */
class ProductGroup extends Model
{
    use HasSyncUuid;

    protected $fillable = [
        'uuid',
        'name',
        'category_id',
        'brand',
        'sku_prefix',
        'base_price',
        'base_cost',
        'unit',
        'image',
        'is_active',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'base_cost' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'unit' => 'piece',
        'is_active' => true,
        'base_price' => 0,
        'base_cost' => 0,
    ];

    protected static function booted(): void
    {
        static::saved(function (ProductGroup $group) {
            if (! $group->wasRecentlyCreated && $group->wasChanged(['name', 'category_id', 'unit', 'base_price'])) {
                app(ProductVariantService::class)->syncGroupFields($group);
            }
        });
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ProductOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<Product, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
