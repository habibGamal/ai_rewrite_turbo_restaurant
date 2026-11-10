<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property int $from_warehouse_id
 * @property int $to_warehouse_id
 * @property string|null $notes
 * @property int|null $user_id
 * @property Carbon|null $closed_at
 * @property int|null $closed_by
 * @property Carbon|null $business_date
 * @property string|float $total_cost
 * @property-read Warehouse $fromWarehouse
 * @property-read Warehouse $toWarehouse
 * @property-read User|null $user
 * @property-read User|null $closedByUser
 * @property-read Collection<int, StockTransferItem> $items
 */
class StockTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'from_warehouse_id',
        'to_warehouse_id',
        'notes',
        'user_id',
        'closed_at',
        'closed_by',
        'business_date',
        'total_cost',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
        'business_date' => 'date',
        'total_cost' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (StockTransfer $transfer) {
            $transfer->from_warehouse_id ??= Warehouse::default()->id;
            $transfer->user_id ??= auth()->id();
        });

        static::updating(function (StockTransfer $transfer) {
            if (static::whereKey($transfer->id)->whereNotNull('closed_at')->exists()) {
                throw ValidationException::withMessages([
                    'stock_transfer' => 'لا يمكن تعديل إذن تحويل مؤكد.',
                ]);
            }
        });

        static::deleting(function (StockTransfer $transfer) {
            if (static::whereKey($transfer->id)->whereNotNull('closed_at')->exists()) {
                throw ValidationException::withMessages([
                    'stock_transfer' => 'لا يمكن حذف إذن تحويل مؤكد.',
                ]);
            }
        });
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return HasMany<StockTransferItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    /**
     * @return MorphMany<InventoryItemMovement, $this>
     */
    public function movements(): MorphMany
    {
        return $this->morphMany(InventoryItemMovement::class, 'referenceable');
    }
}
