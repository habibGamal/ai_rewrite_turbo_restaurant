<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property int $stock_transfer_id
 * @property int $product_id
 * @property string|float $quantity
 * @property string|float $unit_cost
 * @property string|float $total_cost
 * @property-read StockTransfer|null $stockTransfer
 * @property-read Product|null $product
 */
class StockTransferItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_transfer_id',
        'product_id',
        'quantity',
        'unit_cost',
        'total_cost',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (StockTransferItem $item) {
            self::assertParentNotClosed($item);
        });

        static::updating(function (StockTransferItem $item) {
            self::assertParentNotClosed($item);
        });

        static::deleting(function (StockTransferItem $item) {
            self::assertParentNotClosed($item);
        });
    }

    private static function assertParentNotClosed(StockTransferItem $item): void
    {
        if (StockTransfer::whereIn('id', array_filter([$item->stock_transfer_id, $item->getOriginal('stock_transfer_id')]))->whereNotNull('closed_at')->exists()) {
            throw ValidationException::withMessages([
                'items' => 'لا يمكن تعديل أصناف إذن تحويل مؤكد.',
            ]);
        }
    }

    /**
     * @return BelongsTo<StockTransfer, $this>
     */
    public function stockTransfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
