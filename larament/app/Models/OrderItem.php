<?php

namespace App\Models;

use App\Models\Concerns\HasSyncUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory, HasSyncUuid;

    protected $fillable = [
        'uuid',
        'order_id',
        'product_id',
        'quantity',
        'price',
        'cost',
        'total',
        'notes',
        'item_discount',
        'item_discount_type',
        'item_discount_percent',
    ];

    protected $casts = [
        'quantity' => 'double',
        'price' => 'decimal:2',
        'cost' => 'decimal:4',
        'total' => 'decimal:2',
        'item_discount' => 'decimal:2',
        'item_discount_percent' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the breakdown of cashiers who ordered this item and their quantities.
     */
    public function getOrderedByCashiersAttribute(): string
    {
        $changes = OrderItemChange::query()
            ->where('order_id', $this->order_id)
            ->where('product_id', $this->product_id)
            ->whereNotNull('user_id')
            ->with('user')
            ->orderBy('created_at', 'asc')
            ->get();

        if ($changes->isEmpty()) {
            return $this->order?->user?->name ?? 'غير محدد';
        }

        $cashierCounts = [];
        foreach ($changes as $change) {
            $userName = $change->user?->name ?? 'غير محدد';
            $delta = (float) $change->delta;

            if ($change->change_type === 'added' || $delta > 0) {
                $qty = $change->change_type === 'added' ? (float) $change->new_quantity : $delta;
                $cashierCounts[$userName] = ($cashierCounts[$userName] ?? 0) + $qty;
            } elseif ($change->change_type === 'removed' || $delta < 0) {
                $reduceQty = $change->change_type === 'removed' ? (float) $change->old_quantity : abs($delta);
                if (isset($cashierCounts[$userName])) {
                    $cashierCounts[$userName] = max(0, $cashierCounts[$userName] - $reduceQty);
                    if ($cashierCounts[$userName] == 0) {
                        unset($cashierCounts[$userName]);
                    }
                }
            }
        }

        if (empty($cashierCounts)) {
            return $this->order?->user?->name ?? 'غير محدد';
        }

        if (count($cashierCounts) === 1) {
            return (string) array_key_first($cashierCounts);
        }

        $parts = [];
        foreach ($cashierCounts as $name => $qty) {
            $parts[] = "{$name} (".((float) $qty).')';
        }

        return implode('، ', $parts);
    }
}
