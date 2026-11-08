<?php

namespace App\Models;

use App\Enums\KitchenTicketStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Branch-local kitchen ticket. Not synced (no HasSyncUuid).
 *
 * @property int $id
 * @property int $order_id
 * @property KitchenTicketStatus $status
 * @property bool $is_rush
 * @property \Illuminate\Support\Carbon $first_sent_at
 * @property \Illuminate\Support\Carbon|null $done_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property \Illuminate\Support\Carbon|null $seen_at
 * @property \Illuminate\Support\Carbon|null $cancel_acknowledged_at
 * @property bool $auto_finished
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class KitchenTicket extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'status',
        'is_rush',
        'first_sent_at',
        'done_at',
        'cancelled_at',
        'seen_at',
        'cancel_acknowledged_at',
        'auto_finished',
    ];

    protected $casts = [
        'status' => KitchenTicketStatus::class,
        'is_rush' => 'boolean',
        'first_sent_at' => 'datetime',
        'done_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'seen_at' => 'datetime',
        'cancel_acknowledged_at' => 'datetime',
        'auto_finished' => 'boolean',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(KitchenTicketRound::class)->orderBy('number');
    }

    public function items(): HasMany
    {
        return $this->hasMany(KitchenTicketItem::class);
    }

    public function openRound(): HasOne
    {
        return $this->hasOne(KitchenTicketRound::class)->ofMany(
            ['number' => 'max'],
            fn ($query) => $query->whereNull('ended_at'),
        );
    }
}
