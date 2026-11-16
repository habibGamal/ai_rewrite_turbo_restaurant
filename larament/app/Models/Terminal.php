<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One cash drawer / cashier counter («درج»).
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property bool $is_default
 * @property string|null $receipt_printer
 * @property int $sort
 */
class Terminal extends Model
{
    public const DEFAULT_NAME = 'الكاشير';

    protected $fillable = [
        'name',
        'is_active',
        'is_default',
        'receipt_printer',
        'sort',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'sort' => 'integer',
    ];

    /**
     * The hidden drawer every shift uses while multiple terminals are off.
     */
    public static function default(): self
    {
        return self::query()->where('is_default', true)->orderBy('id')->first()
            ?? self::query()->create([
                'name' => self::DEFAULT_NAME,
                'is_active' => true,
                'is_default' => true,
                'sort' => 0,
            ]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return HasMany<TerminalSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(TerminalSession::class);
    }
}
