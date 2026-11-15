<?php

namespace App\Models;

use App\Models\Concerns\HasSyncUuid;
use App\Services\Products\ProductVariantService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $product_option_id
 * @property string $value
 * @property string|null $code
 * @property string|null $color_hex
 * @property int $position
 */
class ProductOptionValue extends Model
{
    use HasSyncUuid;

    protected $fillable = [
        'uuid',
        'product_option_id',
        'value',
        'code',
        'color_hex',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saved(function (ProductOptionValue $value) {
            if (! $value->wasRecentlyCreated && $value->wasChanged('value')) {
                $group = $value->option?->group;
                if ($group !== null) {
                    app(ProductVariantService::class)->refreshLabels($group);
                }
            }
        });

        static::deleting(function (ProductOptionValue $value) {
            app(ProductVariantService::class)->assertValueDeletable($value);
        });
    }

    /** @return BelongsTo<ProductOption, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_variant_values')->withTimestamps();
    }

    /** Short code used in generated SKUs: the explicit code, else the value itself. */
    public function skuCode(): string
    {
        $code = trim((string) ($this->code ?: $this->value));

        return mb_strtoupper(preg_replace('/\s+/u', '', $code) ?? $code);
    }
}
