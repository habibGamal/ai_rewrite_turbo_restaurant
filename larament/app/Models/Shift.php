<?php

namespace App\Models;

use App\Models\Concerns\HasSyncUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon|null $business_date
 * @property Carbon|null $start_at
 * @property Carbon|null $end_at
 * @property Carbon|null $money_edited_at
 * @property int $money_edited_count
 */
class Shift extends Model
{
    use HasFactory, HasSyncUuid;

    protected $fillable = [
        'uuid',
        'user_id',
        'start_at',
        'end_at',
        'business_date',
        'start_cash',
        'end_cash',
        'losses_amount',
        'real_cash',
        'has_deficit',
        'closed',
        'money_edited_at',
        'money_edited_count',
    ];

    protected $casts = [
        'start_cash' => 'decimal:2',
        'end_cash' => 'decimal:2',
        'losses_amount' => 'decimal:2',
        'real_cash' => 'decimal:2',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'business_date' => 'date',
        'has_deficit' => 'boolean',
        'closed' => 'boolean',
        'money_edited_at' => 'datetime',
        'money_edited_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function revenues(): HasMany
    {
        return $this->hasMany(Revenue::class);
    }

    /**
     * Expenses or revenues were changed after the shift closed.
     */
    public function getMoneyEditedAttribute(): bool
    {
        return $this->money_edited_at !== null;
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->end_at === null && ! $this->closed;
    }

    public function getDurationAttribute(): string
    {
        $start = $this->start_at;
        $end = $this->end_at ?? now();
        $duration = $start->diff($end);

        return $duration->format('%H:%I:%S');
    }

    public function getTotalSalesAttribute(): float
    {
        return $this->orders()
            ->where('status', 'completed')
            ->sum('total');
    }

    public function getTotalCashAttribute(): float
    {
        return $this->orders()
            ->where('status', 'completed')
            ->whereHas('payments', function ($query) {
                $query->where('method', 'cash');
            })
            ->sum('total');
    }

    public function getDeficitAttribute(): float
    {
        if (! $this->real_cash || ! $this->end_cash) {
            return 0;
        }

        return $this->end_cash - $this->real_cash;
    }
}
