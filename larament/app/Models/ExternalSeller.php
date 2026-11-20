<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExternalSeller extends Model
{
    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    /** @return HasMany<ExternalProduct, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(ExternalProduct::class);
    }

    /** @return HasMany<ExternalOrder, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(ExternalOrder::class);
    }

    /** @return HasMany<ExternalSellerSettlement, $this> */
    public function settlements(): HasMany
    {
        return $this->hasMany(ExternalSellerSettlement::class);
    }
}
