<?php

namespace App\Models;

use App\Enums\ProductType;
use App\Models\Concerns\HasSyncUuid;
use DB;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int|null $warehouse_id
 * @property int|null $product_group_id
 * @property string|null $variant_label
 * @property string|null $sku
 * @property string|null $product_ref
 * @property ProductType|null $type
 * @property Pivot|null $pivot
 * @property bool $has_custom_price
 * @property bool $is_active
 */
class Product extends Model
{
    use HasFactory, HasSyncUuid;

    protected $fillable = [
        'uuid',
        'category_id',
        'product_group_id',
        'warehouse_id',
        'name',
        'variant_label',
        'product_ref',
        'sku',
        'barcode',
        'price',
        'has_custom_price',
        'cost',
        'min_stock',
        'type',
        'unit',
        'legacy',
        'is_active',
        'kitchen_station_id',
        'show_on_kds',
        'prep_minutes',
    ];

    protected $casts = [
        'type' => ProductType::class,
        'show_on_kds' => 'boolean',
        'prep_minutes' => 'integer',
        'has_custom_price' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        // 'components_hash',
    ];

    public function getRawTypeAttribute(): string
    {
        return $this->type instanceof ProductType ? $this->type->value : (string) ($this->type ?? '');
    }

    public function getComponentsHashAttribute(): string
    {
        return $this->components
            ->map(fn (Product $component) => [$component->product_ref, $component->pivot ? $component->pivot->getAttribute('quantity') : null])
            ->sort()
            ->flatten()
            ->join('');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** The retail group this SKU is a variant of; null for a standalone product. */
    /** @return BelongsTo<ProductGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }

    /** @return BelongsToMany<ProductOptionValue, $this> */
    public function optionValues(): BelongsToMany
    {
        return $this->belongsToMany(ProductOptionValue::class, 'product_variant_values')->withTimestamps();
    }

    public function isVariant(): bool
    {
        return $this->product_group_id !== null;
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function stockWarehouseId(): int
    {
        if ($this->warehouse_id !== null) {
            $this->loadMissing('warehouse');
            if ($this->warehouse instanceof Warehouse && $this->warehouse->is_active) {
                return (int) $this->warehouse_id;
            }
        }

        $this->loadMissing('category');
        $category = $this->category;
        if ($category instanceof Category) {
            $main = $category->mainCategory();
            if ($main->warehouse_id !== null) {
                $main->loadMissing('warehouse');
                if ($main->warehouse instanceof Warehouse && $main->warehouse->is_active) {
                    return (int) $main->warehouse_id;
                }
            }
        }

        return Warehouse::default()->id;
    }

    public function kitchenStation(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class);
    }

    public function printers(): BelongsToMany
    {
        return $this->belongsToMany(Printer::class);
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class);
    }

    /** Barcodes other than the primary one (which mirrors products.barcode). */
    public function extraBarcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class)->where('is_primary', false);
    }

    /**
     * @return HasOne<InventoryItem, $this>
     */
    public function inventoryItem(): HasOne
    {
        return $this->hasOne(InventoryItem::class);
    }

    /**
     * @return HasMany<InventoryItem, $this>
     */
    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function wastedItems(): HasMany
    {
        return $this->hasMany(WastedItem::class);
    }

    public function purchaseInvoiceItems(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceItem::class);
    }

    public function returnPurchaseInvoiceItems(): HasMany
    {
        return $this->hasMany(ReturnPurchaseInvoiceItem::class);
    }

    /** @return BelongsToMany<Product, $this> */
    public function components(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_components', 'product_id', 'component_id')->withPivot('quantity');
    }

    /** @return BelongsToMany<Product, $this> */
    public function componentOf(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_components', 'component_id', 'product_id')->withPivot('quantity');
    }

    public function productComponents(): HasMany
    {
        return $this->hasMany(ProductComponent::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryItemMovement::class);
    }

    public function dailyMovements(): HasMany
    {
        return $this->hasMany(InventoryItemMovementDaily::class);
    }

    public function updateManufacturedCost(array $visited = []): void
    {
        if ($this->type !== ProductType::Manufactured || in_array($this->id, $visited, true)) {
            return;
        }

        $visited[] = $this->id;
        $this->cost = $this->components()->sum(DB::raw('quantity * (select cost from products WHERE products.id  = `product_components`.`component_id`)'));
        $this->save();

        foreach ($this->componentOf()->get() as $parent) {
            $parent->updateManufacturedCost($visited);
        }
    }

    /**
     * Get available unit options mapped to Arabic names
     *
     * @return array<string, string>
     */
    public static function getUnitOptions(): array
    {
        return [
            'packet' => 'باكت',
            'kg' => 'كيلوجرام',
            'gram' => 'جرام',
            'liter' => 'لتر',
            'ml' => 'ميليلتر',
            'piece' => 'قطعة',
            'box' => 'صندوق',
            'bag' => 'كيس',
            'bottle' => 'زجاجة',
            'can' => 'علبة',
            'cup' => 'كوب',
            'tablespoon' => 'ملعقة كبيرة',
            'teaspoon' => 'ملعقة صغيرة',
            'dozen' => 'دستة',
            'meter' => 'متر',
            'cm' => 'سنتيمتر',
            'roll' => 'رول',
            'sheet' => 'ورقة',
            'slice' => 'شريحة',
            'loaf' => 'رغيف',
            'plate' => 'طبق',
            'dish' => 'طبق',
        ];
    }

    /**
     * Format a unit value to its Arabic display name
     */
    public static function formatUnit(?string $unit): string
    {
        if (empty($unit)) {
            return '-';
        }

        return static::getUnitOptions()[$unit] ?? $unit;
    }

    public function getUnitNameAttribute(): string
    {
        return static::formatUnit($this->unit);
    }
}
