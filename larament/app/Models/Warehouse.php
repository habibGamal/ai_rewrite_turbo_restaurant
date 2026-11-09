<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Warehouse extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'is_default', 'is_active'];

    protected $casts = ['is_default' => 'boolean', 'is_active' => 'boolean'];

    protected $attributes = ['is_default' => false, 'is_active' => true];

    protected static function booted(): void
    {
        static::saving(function (Warehouse $warehouse) {
            if ($warehouse->is_default && ! $warehouse->is_active) {
                throw ValidationException::withMessages(['is_active' => 'لا يمكن تعطيل المخزن الافتراضي.']);
            }

            if ($warehouse->exists && static::whereKey($warehouse->id)->where('is_default', true)->exists() && ! $warehouse->is_default) {
                throw ValidationException::withMessages(['is_default' => 'اختر مخزناً آخر ليكون الافتراضي أولاً.']);
            }
        });

        static::saved(function (Warehouse $warehouse) {
            if ($warehouse->is_default) {
                static::whereKeyNot($warehouse->id)->where('is_default', true)->update(['is_default' => false]);
            }
            request()->attributes->remove(self::class);
        });

        static::deleting(function (Warehouse $warehouse) {
            if (static::whereKey($warehouse->id)->where('is_default', true)->exists()) {
                throw ValidationException::withMessages(['warehouse' => 'لا يمكن حذف المخزن الافتراضي.']);
            }

            if ($warehouse->movements()->exists()) {
                throw ValidationException::withMessages(['warehouse' => 'لا يمكن حذف مخزن له حركات مخزنية. يمكنك تعطيله.']);
            }
        });
    }

    public static function default(): Warehouse
    {
        if (! request()->attributes->has(self::class)) {
            request()->attributes->set(self::class, static::where('is_default', true)->sole());
        }

        return clone request()->attributes->get(self::class);
    }

    public function save(array $options = []): bool
    {
        return DB::transaction(function () use ($options) {
            static::orderBy('id')->lockForUpdate()->get();

            return parent::save($options);
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryItemMovement::class);
    }
}
