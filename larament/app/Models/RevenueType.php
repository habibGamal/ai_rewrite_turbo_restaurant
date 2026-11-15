<?php

namespace App\Models;

use App\Models\Concerns\HasSyncUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A category of misc shift revenue («بند إيراد»), like an expense type.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property bool $is_active
 * @property int $sort_order
 */
class RevenueType extends Model
{
    use HasFactory, HasSyncUuid;

    protected $fillable = [
        'uuid',
        'name',
        'is_active',
        'sort_order',
    ];

    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * @return HasMany<Revenue, $this>
     */
    public function revenues(): HasMany
    {
        return $this->hasMany(Revenue::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
