<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTerminalSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An instant-sale cart parked with F8. It is not an order: no number, no
 * stock, no payments. Resuming it deletes the row when the sale is paid.
 */
class HeldSale extends Model
{
    use BelongsToTerminalSession;

    protected $fillable = [
        'shift_id',
        'user_id',
        'order_type',
        'label',
        'lines',
        'meta',
        'total',
    ];

    protected $casts = [
        'lines' => 'array',
        'meta' => 'array',
        'total' => 'decimal:2',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
