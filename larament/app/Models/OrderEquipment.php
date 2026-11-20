<?php

namespace App\Models;

use App\Models\Concerns\HasSyncUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One equipment line on an order. Name, unit price and unit cost are a
 * snapshot taken when the line was added.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $equipment_id
 * @property string $name
 * @property int $quantity
 * @property string $unit_price
 * @property string $unit_cost
 * @property string $total
 * @property string $cost_total
 * @property int|null $user_id
 */
class OrderEquipment extends Model
{
    use HasSyncUuid;

    protected $table = 'order_equipment';

    protected $fillable = [
        'uuid',
        'order_id',
        'equipment_id',
        'name',
        'quantity',
        'unit_price',
        'unit_cost',
        'total',
        'cost_total',
        'user_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'total' => 'decimal:2',
        'cost_total' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Refunds of this line («مرتجع»). */
    public function returnLines(): HasMany
    {
        return $this->hasMany(OrderReturnItem::class, 'order_equipment_id');
    }
}
