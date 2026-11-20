<?php

namespace App\Models;

use App\Models\Concerns\HasSyncUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property string $name
 * @property int|null $parent_id
 * @property int|null $kitchen_station_id
 * @property int|null $warehouse_id
 */
class Category extends Model
{
    use HasFactory, HasSyncUuid;

    protected $fillable = [
        'uuid',
        'name',
        'parent_id',
        'warehouse_id',
        'kitchen_station_id',
        'show_on_kds',
        'prep_minutes',
    ];

    protected $casts = [
        'show_on_kds' => 'boolean',
        'prep_minutes' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (Category $category): void {
            if ($category->parent_id !== null) {
                $category->warehouse_id = null;
            }

            $category->assertHierarchy();
        });
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function kitchenStation(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function blockedOrderTypes(): BelongsToMany
    {
        return $this->belongsToMany(OrderTypeRow::class, 'category_blocked_order_types', 'category_id', 'order_type_id');
    }

    public function mainCategory(): self
    {
        $this->loadMissing('parent');

        return $this->parent instanceof self ? $this->parent : $this;
    }

    /**
     * @return list<int>
     */
    public function blockedOrderTypeIds(): array
    {
        $this->loadMissing(['blockedOrderTypes', 'parent.blockedOrderTypes']);

        $ids = $this->blockedOrderTypes->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $parent = $this->parent;

        if ($parent instanceof self) {
            $ids = array_merge(
                $ids,
                $parent->blockedOrderTypes->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            );
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>
     */
    public function blockedOrderTypeCodes(): array
    {
        $this->loadMissing(['blockedOrderTypes', 'parent.blockedOrderTypes']);

        $codes = $this->blockedOrderTypes->pluck('code')->map(fn (mixed $code): string => (string) $code)->all();
        $parent = $this->parent;

        if ($parent instanceof self) {
            $codes = array_merge(
                $codes,
                $parent->blockedOrderTypes->pluck('code')->map(fn (mixed $code): string => (string) $code)->all(),
            );
        }

        return array_values(array_unique($codes));
    }

    public function isBlockedFor(string $orderTypeCode): bool
    {
        return in_array($orderTypeCode, $this->blockedOrderTypeCodes(), true);
    }

    public function effectiveKitchenStationId(): ?int
    {
        if ($this->kitchen_station_id !== null) {
            return (int) $this->kitchen_station_id;
        }

        $this->loadMissing('parent');
        $parent = $this->parent;

        if (! $parent instanceof self || $parent->kitchen_station_id === null) {
            return null;
        }

        return (int) $parent->kitchen_station_id;
    }

    public function assertHierarchy(): void
    {
        if ($this->parent_id === null) {
            return;
        }

        if ($this->exists && (int) $this->parent_id === (int) $this->getKey()) {
            throw ValidationException::withMessages([
                'parent_id' => 'لا يمكن أن يكون التصنيف أباً لنفسه.',
            ]);
        }

        if ($this->exists && $this->children()->exists()) {
            throw ValidationException::withMessages([
                'parent_id' => 'لا يمكن جعل تصنيف له تصنيفات فرعية تصنيفاً فرعياً.',
            ]);
        }

        $parent = self::query()->find($this->parent_id);

        if (! $parent instanceof self || $parent->parent_id !== null) {
            throw ValidationException::withMessages([
                'parent_id' => 'لا يمكن إنشاء أكثر من مستويين للتصنيفات.',
            ]);
        }
    }
}
