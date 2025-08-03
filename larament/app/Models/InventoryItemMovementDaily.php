<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class InventoryItemMovementDaily extends Model
{
    use HasFactory;

    protected $table = 'inventory_item_movement_daily';

    protected $fillable = [
        'product_id',
        'date',
        'start_quantity',
        'incoming_quantity',
        'sales_quantity',
        'return_waste_quantity',
    ];

    protected $casts = [
        'date' => 'date',
        'start_quantity' => 'decimal:2',
        'incoming_quantity' => 'decimal:2',
        'return_sales_quantity' => 'decimal:2',
        'sales_quantity' => 'decimal:2',
        'return_waste_quantity' => 'decimal:2',
    ];

    /**
     * Get the product that owns the daily movement
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Calculate the end quantity for the day
     */
    public function getEndQuantityAttribute(): float
    {
        return $this->start_quantity + $this->incoming_quantity - $this->sales_quantity - $this->return_waste_quantity;
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
}
