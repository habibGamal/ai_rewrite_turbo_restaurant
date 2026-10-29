<?php

namespace App\Models;

use App\Models\Concerns\HasSyncUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory, HasSyncUuid;

    protected $fillable = [
        'uuid',
        'name',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
