<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalProduct extends Model
{
    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean', 'price' => 'decimal:2'];

    /** @return BelongsTo<ExternalSeller, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(ExternalSeller::class, 'external_seller_id');
    }
}
