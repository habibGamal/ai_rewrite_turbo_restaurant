<?php

namespace App\Models;

use App\Models\Concerns\HasSyncUuid;
use App\Services\Products\ProductVariantService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One option axis of a product group (size, colour, fit...). A group may have any number of axes.
 *
 * @property int $id
 * @property string $uuid
 * @property int $product_group_id
 * @property string $name
 * @property int $position
 */
class ProductOption extends Model
{
    use HasSyncUuid;

    protected $fillable = [
        'uuid',
        'product_group_id',
        'name',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saved(function (ProductOption $option) {
            if (! $option->wasRecentlyCreated && $option->wasChanged('position') && $option->group) {
                app(ProductVariantService::class)->refreshLabels($option->group);
            }
        });

        static::deleting(function (ProductOption $option) {
            foreach ($option->values as $value) {
                app(ProductVariantService::class)->assertValueDeletable($value);
            }
        });
    }

    /** @return BelongsTo<ProductGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }

    /** @return HasMany<ProductOptionValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(ProductOptionValue::class)->orderBy('position')->orderBy('id');
    }
}
