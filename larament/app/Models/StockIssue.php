<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property int $warehouse_id
 * @property string $destination
 * @property string|null $notes
 * @property int|null $user_id
 * @property Carbon|null $closed_at
 * @property int|null $closed_by
 * @property Carbon|null $business_date
 * @property string|float $total_cost
 * @property string|float $total
 * @property-read Warehouse $warehouse
 * @property-read User|null $user
 * @property-read User|null $closedByUser
 * @property-read Collection<int, StockIssueItem> $items
 */
class StockIssue extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_id',
        'destination',
        'notes',
        'user_id',
        'closed_at',
        'closed_by',
        'business_date',
        'total_cost',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
        'business_date' => 'date',
        'total_cost' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (StockIssue $issue) {
            $issue->destination = trim((string) $issue->destination);
            $issue->warehouse_id ??= Warehouse::default()->id;
            $issue->user_id ??= auth()->id();
        });

        static::updating(function (StockIssue $issue) {
            if ($issue->getOriginal('closed_at') !== null || static::whereKey($issue->getKey())->whereNotNull('closed_at')->exists()) {
                throw ValidationException::withMessages([
                    'stock_issue' => 'لا يمكن تعديل إذن صرف مؤكد.',
                ]);
            }
        });

        static::deleting(function (StockIssue $issue) {
            if ($issue->closed_at !== null) {
                throw ValidationException::withMessages([
                    'stock_issue' => 'لا يمكن حذف إذن صرف مؤكد.',
                ]);
            }
        });
    }

    /**
     * @return list<string>
     */
    public static function distinctDestinations(): array
    {
        return static::query()
            ->whereNotNull('destination')
            ->where('destination', '!=', '')
            ->orderBy('destination')
            ->pluck('destination')
            ->map(fn ($destination): string => trim((string) $destination))
            ->filter(fn (string $destination): bool => $destination !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return HasMany<StockIssueItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockIssueItem::class);
    }

    /**
     * @return MorphMany<InventoryItemMovement, $this>
     */
    public function movements(): MorphMany
    {
        return $this->morphMany(InventoryItemMovement::class, 'referenceable');
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
