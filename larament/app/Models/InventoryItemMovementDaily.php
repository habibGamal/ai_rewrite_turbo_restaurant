<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $warehouse_id */
class InventoryItemMovementDaily extends Model
{
    use HasFactory;

    protected $table = 'inventory_item_movement_daily';

    protected $fillable = [
        'product_id',
        'warehouse_id',
        'transfer_in_quantity',
        'transfer_out_quantity',
        'manufacturing_in_quantity',
        'manufacturing_out_quantity',
        'issue_quantity',
        'date',
        'start_quantity',
        'incoming_quantity',
        'return_sales_quantity',
        'sales_quantity',
        'return_waste_quantity',
        'end_quantity',
        'closed_at',
    ];

    protected $casts = [
        'date' => 'date',
        'start_quantity' => 'decimal:3',
        'incoming_quantity' => 'decimal:3',
        'return_sales_quantity' => 'decimal:3',
        'sales_quantity' => 'decimal:3',
        'return_waste_quantity' => 'decimal:3',
        'end_quantity' => 'decimal:3',
        'closed_at' => 'datetime',
        'transfer_in_quantity' => 'decimal:3',
        'transfer_out_quantity' => 'decimal:3',
        'manufacturing_in_quantity' => 'decimal:3',
        'manufacturing_out_quantity' => 'decimal:3',
        'issue_quantity' => 'decimal:3',
    ];

    protected static function booted(): void
    {
        static::creating(function (InventoryItemMovementDaily $daily) {
            $daily->warehouse_id ??= Warehouse::default()->id;
        });
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Get the product that owns the daily movement
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Calculate total outgoing quantity (sales + returns/waste)
     */
    public function getTotalOutgoingAttribute(): float
    {
        return $this->sales_quantity + $this->return_waste_quantity;
    }

    /**
     * Calculate net change for the day
     */
    public function getNetChangeAttribute(): float
    {
        return $this->incoming_quantity - $this->total_outgoing;
    }

    /**
     * Scope to filter by date range
     */
    public function scopeForDateRange($query, Carbon $startDate, Carbon $endDate)
    {
        return $query->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()]);
    }

    /**
     * Scope to filter by product
     */
    public function scopeForProduct($query, int $productId)
    {
        return $query->where('product_id', $productId);
    }

    /**
     * Scope to filter by today
     */
    public function scopeToday($query)
    {
        return $query->where('date', Carbon::today()->toDateString());
    }

    /**
     * Scope to order by date descending
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('date', 'desc');
    }

    /**
     * Scope to filter open days (where closed_at is null)
     */
    public function scopeOpen($query)
    {
        return $query->whereNull('closed_at');
    }
}
