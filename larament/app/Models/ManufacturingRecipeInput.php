<?php

namespace App\Models;

use App\Enums\ProductType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ManufacturingRecipeInput extends Model
{
    use HasFactory;

    protected $fillable = [
        'recipe_id',
        'manufacturing_recipe_id',
        'product_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $input): void {
            $product = $input->relationLoaded('product')
                ? $input->product
                : Product::find($input->product_id);

            if (! $product || ! in_array($product->type, [ProductType::RawMaterial, ProductType::Consumable], true)) {
                throw ValidationException::withMessages([
                    'inputs' => 'المدخلات يجب أن تكون مواد خام أو منتجات استهلاكية فقط',
                ]);
            }

            if ((float) $input->quantity <= 0) {
                throw ValidationException::withMessages([
                    'inputs' => 'يجب أن تكون كمية المدخل أكبر من صفر',
                ]);
            }
        });
    }

    public function setManufacturingRecipeIdAttribute(mixed $value): void
    {
        $this->attributes['recipe_id'] = $value;
    }

    public function getManufacturingRecipeIdAttribute(): ?int
    {
        return isset($this->attributes['recipe_id']) ? (int) $this->attributes['recipe_id'] : null;
    }

    /** @return BelongsTo<ManufacturingRecipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(ManufacturingRecipe::class, 'recipe_id');
    }

    /** @return BelongsTo<ManufacturingRecipe, $this> */
    public function manufacturingRecipe(): BelongsTo
    {
        return $this->belongsTo(ManufacturingRecipe::class, 'recipe_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
