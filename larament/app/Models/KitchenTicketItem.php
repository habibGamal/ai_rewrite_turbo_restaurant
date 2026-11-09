<?php

namespace App\Models;

use App\Enums\KitchenItemKind;
use App\Enums\KitchenItemStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Branch-local kitchen ticket line. Not synced (no HasSyncUuid).
 *
 * @property int $id
 * @property int $kitchen_ticket_id
 * @property int $kitchen_ticket_round_id
 * @property int|null $product_id
 * @property string $product_name
 * @property int|null $kitchen_station_id
 * @property float $quantity
 * @property float $original_quantity
 * @property string|null $notes
 * @property KitchenItemKind $kind
 * @property KitchenItemStatus $status
 * @property int $target_minutes
 * @property \Illuminate\Support\Carbon $sent_at
 * @property \Illuminate\Support\Carbon|null $done_at
 * @property \Illuminate\Support\Carbon|null $voided_at
 * @property int|null $done_by
 * @property bool $voided_after_done
 * @property bool $needs_ack
 * @property bool $auto_finished
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class KitchenTicketItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'kitchen_ticket_id',
        'kitchen_ticket_round_id',
        'product_id',
        'product_name',
        'kitchen_station_id',
        'quantity',
        'original_quantity',
        'notes',
        'kind',
        'status',
        'target_minutes',
        'sent_at',
        'done_at',
        'voided_at',
        'done_by',
        'voided_after_done',
        'needs_ack',
        'auto_finished',
    ];

    protected $casts = [
        'quantity' => 'float',
        'original_quantity' => 'float',
        'kind' => KitchenItemKind::class,
        'status' => KitchenItemStatus::class,
        'target_minutes' => 'integer',
        'sent_at' => 'datetime',
        'done_at' => 'datetime',
        'voided_at' => 'datetime',
        'voided_after_done' => 'boolean',
        'needs_ack' => 'boolean',
        'auto_finished' => 'boolean',
    ];

    public function kitchenTicket(): BelongsTo
    {
        return $this->belongsTo(KitchenTicket::class);
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(KitchenTicketRound::class, 'kitchen_ticket_round_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class, 'kitchen_station_id');
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
