<?php

namespace App\Models;

use App\Enums\ProductType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ManufacturingRecipeOutput extends Model
{
    use HasFactory;

    protected $fillable = [
        'recipe_id',
        'manufacturing_recipe_id',
        'product_id',
        'is_main',
        'expected_quantity',
        'cost_method',
        'cost_value',
    ];

    protected $casts = [
        'is_main' => 'boolean',
        'expected_quantity' => 'decimal:3',
        'cost_value' => 'decimal:4',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $output): void {
            $output->cost_method = $output->cost_method ?: 'percent';
            $output->cost_value = $output->is_main ? 0 : ($output->cost_value ?? 0);

            $product = $output->relationLoaded('product')
                ? $output->product
                : Product::find($output->product_id);

            if (! $product || ! in_array($product->type, [ProductType::RawMaterial, ProductType::Consumable], true)) {
                throw ValidationException::withMessages([
                    'outputs' => 'المخرجات يجب أن تكون مواد خام أو منتجات استهلاكية فقط',
                ]);
            }

            if ((float) $output->expected_quantity <= 0) {
                throw ValidationException::withMessages([
                    'outputs' => 'يجب أن تكون الكمية المتوقعة للمخرج أكبر من صفر',
                ]);
            }

            if (! $output->is_main) {
                if (! in_array($output->cost_method, ['percent', 'fixed'], true)) {
                    throw ValidationException::withMessages([
                        'outputs' => 'طريقة حساب التكلفة للمخرج غير صالحة',
                    ]);
                }

                if ((float) $output->cost_value < 0) {
                    throw ValidationException::withMessages([
                        'outputs' => 'لا يمكن أن تكون قيمة تكلفة المخرج سالبة',
                    ]);
                }
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
