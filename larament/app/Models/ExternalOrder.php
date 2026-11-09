<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExternalOrder extends Model
{
    protected $guarded = [];

    public function host(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'host_order_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(ExternalSeller::class, 'external_seller_id');
    }

    /** @return HasMany<ExternalOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ExternalOrderItem::class);
    }

    /** @return HasMany<ExternalOrderCollection, $this> */
    public function collections(): HasMany
    {
        return $this->hasMany(ExternalOrderCollection::class);
    }
}
