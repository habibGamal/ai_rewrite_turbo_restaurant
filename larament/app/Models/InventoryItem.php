<?php

namespace App\Models;

use App\Models\Relations\WarehouseHasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $product_id
 * @property int $warehouse_id
 * @property float|string $quantity
 */
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

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<InventoryItemMovement, $this>
     */
    public function movements(): HasMany
    {
        return new WarehouseHasMany(
            $this->newRelatedInstance(InventoryItemMovement::class)->newQuery(),
            $this,
            'inventory_item_movements.product_id',
            'product_id',
        );
    }

    /**
     * @return HasMany<InventoryItemMovementDaily, $this>
     */
    public function dailyMovements(): HasMany
    {
        return new WarehouseHasMany(
            $this->newRelatedInstance(InventoryItemMovementDaily::class)->newQuery(),
            $this,
            'inventory_item_movement_daily.product_id',
            'product_id',
        );
    }

    /**
     * @return HasMany<self, $this>
     */
    public function warehouseBalances(): HasMany
    {
        return $this->hasMany(self::class, 'product_id', 'product_id');
    }

    /**
     * @return HasMany<ProductComponent, $this>
     */
    public function usedInProducts(): HasMany
    {
        return $this->hasMany(ProductComponent::class, 'component_id', 'product_id');
    }
}
