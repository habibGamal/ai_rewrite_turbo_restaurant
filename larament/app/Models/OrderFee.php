<?php

namespace App\Models;

use App\Models\Concerns\HasSyncUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A custom fee line on an order («رسوم إضافية»), e.g. «رسوم دخول تورتة».
 * Part of the bill and pure revenue (no cost). Written only through
 * OrderFeeService, which keeps orders.fees_total in step.
 *
 * @property int $id
 * @property int $order_id
 * @property string $name
 * @property string $name_key
 * @property string $amount
 * @property int|null $user_id
 */
class OrderFee extends Model
{
    use HasSyncUuid;

    protected $fillable = [
        'uuid',
        'order_id',
        'name',
        'name_key',
        'amount',
        'user_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    protected $hidden = ['name_key'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Trimmed, single-spaced, lower-cased: the grouping key for a fee name.
     */
    public static function keyFor(string $name): string
    {
        return mb_strtolower(self::cleanName($name));
    }

    /**
     * The name as stored: trimmed, inner whitespace collapsed to one space.
     */
    public static function cleanName(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }
}
