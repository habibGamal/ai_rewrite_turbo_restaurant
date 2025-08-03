<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name',
        'price',
        'cost',
        'type',
        'unit',
        'printer_id',
        'legacy',
    ];

    protected $casts = [
        'type' => \App\Enums\ProductType::class,
    ];

    public function getRawTypeAttribute(): string
    {
        return $this->type?->value ?? '';
    }


    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function inventoryItem(): HasOne
    {
        return $this->hasOne(InventoryItem::class);
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

    public function components(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_components', 'product_id', 'component_id')->withPivot('quantity');
    }

    public function componentOf(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_components', 'component_id', 'product_id')->withPivot('quantity');
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryItemMovement::class);
    }

    public function dailyMovements(): HasMany
    {
        return $this->hasMany(InventoryItemMovementDaily::class);
    }
}
