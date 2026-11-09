<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $warehouse_id */
class InventoryItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'warehouse_id',
        'quantity',
    ];

    protected $casts = ['quantity' => 'decimal:3'];

    protected static function booted(): void
    {
        static::creating(function (InventoryItem $item) {
            $item->warehouse_id ??= Warehouse::default()->id;
        });
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function movements()
    {
        return $this->hasMany(InventoryItemMovement::class, 'product_id', 'product_id');
    }

    public function usedInProducts()
    {
        return $this->hasMany(ProductComponent::class, 'component_id', 'product_id');
    }
}
