<?php

namespace App\Models;

use App\Models\Concerns\HasSyncUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something rented with an order for a flat price per use (shisha, gaming
 * station, catering warmer). Behind the vendor's EQUIPMENT feature.
 *
 * @property int $id
 * @property string $name
 * @property string $usage_price
 * @property string $usage_cost
 * @property bool $is_active
 * @property int $sort_order
 */
class Equipment extends Model
{
    use HasSyncUuid;

    protected $table = 'equipment';

    protected $fillable = [
        'uuid',
        'name',
        'usage_price',
        'usage_cost',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'usage_price' => 'decimal:2',
        'usage_cost' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * @return BelongsToMany<OrderTypeRow, $this>
     */
    public function orderTypes(): BelongsToMany
    {
        return $this->belongsToMany(OrderTypeRow::class, 'equipment_order_type', 'equipment_id', 'order_type_id');
    }

    /**
     * @return HasMany<OrderEquipment, $this>
     */
    public function orderLines(): HasMany
    {
        return $this->hasMany(OrderEquipment::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
