<?php

namespace App\Models;

use App\Exceptions\ManufacturingException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManufacturingOrderOutput extends Model
{
    protected $fillable = [
        'manufacturing_order_id', 'product_id', 'is_main', 'cost_method', 'cost_value',
        'expected_quantity', 'actual_quantity', 'allocated_cost', 'unit_cost', 'variance_quantity', 'variance_value',
    ];

    protected $casts = [
        'is_main' => 'boolean', 'cost_value' => 'decimal:4', 'expected_quantity' => 'decimal:3',
        'actual_quantity' => 'decimal:3', 'allocated_cost' => 'decimal:2', 'unit_cost' => 'decimal:4',
        'variance_quantity' => 'decimal:3', 'variance_value' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $output): void {
            $output->order()->firstOrFail()->assertDraft();
            if ($output->exists && $output->isDirty(['manufacturing_order_id', 'product_id', 'is_main', 'cost_method', 'cost_value', 'expected_quantity'])) {
                throw new ManufacturingException('لا يمكن تعديل المخرجات المنسوخة من الوصفة');
            }
        });
        static::deleting(function (): void {
            throw new ManufacturingException('لا يمكن حذف مخرجات أمر التصنيع');
        });
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
