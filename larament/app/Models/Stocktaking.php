<?php

namespace App\Models;

use App\Models\Concerns\HasSyncUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $warehouse_id */
class Stocktaking extends Model
{
    use HasFactory, HasSyncUuid;

    protected $fillable = [
        'uuid',
        'user_id',
        'warehouse_id',
        'notes',
        'total',
        'closed_at',
        'business_date',
    ];

    protected $casts = [
        'business_date' => 'date',
        'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Stocktaking $stocktaking): void {
            $stocktaking->warehouse_id ??= Warehouse::default()->id;
        });
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(StocktakingItem::class);
    }
}
