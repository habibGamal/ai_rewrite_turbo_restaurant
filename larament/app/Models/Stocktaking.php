<?php

namespace App\Models;

use App\Models\Concerns\HasSyncUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Stocktaking extends Model
{
    use HasFactory, HasSyncUuid;

    protected $fillable = [
        'uuid',
        'user_id',
        'notes',
        'total',
        'closed_at',
        'business_date',
    ];

    protected $casts = [
        'business_date' => 'date',
        'closed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(StocktakingItem::class);
    }
}
