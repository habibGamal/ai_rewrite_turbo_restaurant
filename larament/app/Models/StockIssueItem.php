<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property int $stock_issue_id
 * @property int $product_id
 * @property string|float $quantity
 * @property string|float $unit_cost
 * @property string|float $total_cost
 * @property string|float $price
 * @property string|float $total
 * @property-read StockIssue|null $stockIssue
 * @property-read Product|null $product
 */
class StockIssueItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_issue_id',
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
        static::creating(function (StockIssueItem $item) {
            self::assertParentNotClosed($item);
        });

        static::updating(function (StockIssueItem $item) {
            self::assertParentNotClosed($item);
        });

        static::deleting(function (StockIssueItem $item) {
            self::assertParentNotClosed($item);
        });
    }

    private static function assertParentNotClosed(StockIssueItem $item): void
    {
        if ($item->stock_issue_id && StockIssue::whereKey($item->stock_issue_id)->whereNotNull('closed_at')->exists()) {
            throw ValidationException::withMessages([
                'items' => 'لا يمكن تعديل أصناف إذن صرف مؤكد.',
            ]);
        }
    }

    /**
     * @return BelongsTo<StockIssue, $this>
     */
    public function stockIssue(): BelongsTo
    {
        return $this->belongsTo(StockIssue::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return Attribute<string|float, string|float>
     */
    public function price(): Attribute
    {
        return Attribute::make(
            get: fn (): string|float => $this->unit_cost,
            set: fn ($value): array => ['unit_cost' => $value],
        );
    }

    /**
     * @return Attribute<string|float, string|float>
     */
    public function total(): Attribute
    {
        return Attribute::make(
            get: fn (): string|float => $this->total_cost,
            set: fn ($value): array => ['total_cost' => $value],
        );
    }
}
