<?php

namespace App\Models;

use App\Enums\RevenueMethod;
use App\Models\Concerns\BelongsToTerminalSession;
use App\Models\Concerns\HasSyncUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Misc money taken in a shift that is not an order (no items, no customer,
 * no stock). Entered by the manager only; the cashier never sees revenues.
 *
 * @property int $id
 * @property string $uuid
 * @property int $shift_id
 * @property int $revenue_type_id
 * @property int|null $user_id
 * @property string $amount
 * @property RevenueMethod $method
 * @property string|null $notes
 */
class Revenue extends Model
{
    use BelongsToTerminalSession, HasFactory, HasSyncUuid;

    protected $fillable = [
        'uuid',
        'shift_id',
        'revenue_type_id',
        'user_id',
        'amount',
        'method',
        'notes',
    ];

    protected $attributes = [
        'method' => 'cash',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'method' => RevenueMethod::class,
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    /**
     * @return BelongsTo<RevenueType, $this>
     */
    public function revenueType(): BelongsTo
    {
        return $this->belongsTo(RevenueType::class);
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
