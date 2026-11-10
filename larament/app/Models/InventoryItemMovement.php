<?php

namespace App\Models;

use App\Enums\InventoryMovementOperation;
use App\Enums\MovementReason;
use App\Observers\InventoryItemMovementObserver;
use App\Services\InventoryDailyAggregationService;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $warehouse_id
 * @property \Carbon\Carbon|null $business_date
 * @property-read Product $product
 */
#[ObservedBy([InventoryItemMovementObserver::class])]
class InventoryItemMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'warehouse_id',
        'business_date',
        'unit_cost',
        'total_cost',
        'user_id',
        'operation',
        'quantity',
        'reason',
        'notes',
        'referenceable_type',
        'referenceable_id',
    ];

    protected $casts = [
        'business_date' => 'date',
        'operation' => InventoryMovementOperation::class,
        'reason' => MovementReason::class,
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (InventoryItemMovement $movement) {
            $movement->warehouse_id ??= Warehouse::default()->id;
            $movement->business_date ??= app(InventoryDailyAggregationService::class)->currentBusinessDate()->toDateString();
        });
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function referenceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeIncoming($query)
    {
        return $query->where('operation', InventoryMovementOperation::IN);
    }

    public function scopeOutgoing($query)
    {
        return $query->where('operation', InventoryMovementOperation::OUT);
    }

    public function scopeByReason($query, MovementReason $reason)
    {
        return $query->where('reason', $reason);
    }

    public function scopeByProduct($query, int $productId)
    {
        return $query->where('product_id', $productId);
    }
}
