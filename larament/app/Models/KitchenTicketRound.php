<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Branch-local kitchen ticket round. Not synced (no HasSyncUuid).
 *
 * @property int $id
 * @property int $kitchen_ticket_id
 * @property int $number
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class KitchenTicketRound extends Model
{
    use HasFactory;

    protected $fillable = [
        'kitchen_ticket_id',
        'number',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'number' => 'integer',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function kitchenTicket(): BelongsTo
    {
        return $this->belongsTo(KitchenTicket::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(KitchenTicketItem::class);
    }
}
