<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One returned line. Exactly one of order_item_id (an item, by quantity),
 * order_fee_id (a custom fee, by amount; quantity stays 0) or
 * order_equipment_id (equipment, by quantity) is set. Only item lines move
 * stock; fee and equipment lines are always stored with restocked = false.
 *
 * @property int $id
 * @property int $order_return_id
 * @property int|null $order_item_id
 * @property int|null $order_fee_id
 * @property int|null $order_equipment_id
 */
class OrderReturnItem extends Model
{
    use HasFactory;

    public const KIND_ITEM = 'item';

    public const KIND_FEE = 'fee';

    public const KIND_EQUIPMENT = 'equipment';

    protected $fillable = [
        'order_return_id',
        'order_item_id',
        'order_fee_id',
        'order_equipment_id',
        'quantity',
        'refund_amount',
        'restocked',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'restocked' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (OrderReturnItem $item): void {
            $set = collect([$item->order_item_id, $item->order_fee_id, $item->order_equipment_id])->filter()->count();
            if ($set !== 1) {
                throw new LogicException('A returned line must point to exactly one item, fee or equipment line.');
            }
        });
    }

    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** @return BelongsTo<OrderFee, $this> */
    public function orderFee(): BelongsTo
    {
        return $this->belongsTo(OrderFee::class);
    }

    /** @return BelongsTo<OrderEquipment, $this> */
    public function orderEquipment(): BelongsTo
    {
        return $this->belongsTo(OrderEquipment::class);
    }

    public function kind(): string
    {
        return match (true) {
            $this->order_fee_id !== null => self::KIND_FEE,
            $this->order_equipment_id !== null => self::KIND_EQUIPMENT,
            default => self::KIND_ITEM,
        };
    }

    /** What the line was, for tables: the product, «رسوم: …» or «معدات: …». */
    public function lineName(): string
    {
        return match ($this->kind()) {
            self::KIND_FEE => 'رسوم: '.($this->orderFee?->name ?? '-'),
            self::KIND_EQUIPMENT => 'معدات: '.($this->orderEquipment?->name ?? '-'),
            default => (string) ($this->orderItem?->product?->name ?? '-'),
        };
    }

    /** Eager loads that lineName() needs. */
    public const LINE_NAME_RELATIONS = ['orderItem.product', 'orderFee', 'orderEquipment'];
}
