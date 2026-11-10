<?php

namespace App\Models;

use App\Enums\ProductType;
use App\Exceptions\ManufacturingException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ManufacturingOrderInput extends Model
{
    protected $fillable = ['manufacturing_order_id', 'product_id', 'planned_quantity', 'actual_quantity', 'unit_cost', 'total_cost'];

    protected $casts = [
        'planned_quantity' => 'decimal:3', 'actual_quantity' => 'decimal:3',
        'unit_cost' => 'decimal:4', 'total_cost' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $input): void {
            $order = $input->order()->firstOrFail();
            $order->assertDraft();
            if ($input->exists && $input->isDirty(['manufacturing_order_id', 'product_id', 'planned_quantity'])) {
                throw new ManufacturingException('لا يمكن تغيير المنتج أو الكمية المخططة للمدخل؛ يمكن تعديل الكمية الفعلية');
            }
            $product = Product::find($input->product_id);
            if (! $product || ! in_array($product->type, [ProductType::RawMaterial, ProductType::Consumable], true)
                || $order->outputs()->where('product_id', $input->product_id)->exists()) {
                throw ValidationException::withMessages(['inputs' => 'يجب اختيار خامة أو مستهلك غير موجود في المخرجات']);
            }
            $actualQuantity = $input->getAttributes()['actual_quantity'] ?? null;
            if (! is_numeric($actualQuantity) || ! is_finite((float) $actualQuantity) || (float) $actualQuantity < 0) {
                throw ValidationException::withMessages(['inputs' => 'كمية المدخل الفعلية يجب ألا تكون سالبة']);
            }
        });
        static::deleting(fn (self $input) => $input->order()->firstOrFail()->assertDraft());
    }

    /** @return BelongsTo<ManufacturingOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ManufacturingOrder::class, 'manufacturing_order_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
