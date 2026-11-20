<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A barcode that resolves to a product. A product can have many barcodes
 * (manufacturer EAN, shop label, a case barcode with pack_quantity > 1);
 * each barcode belongs to exactly one product.
 *
 * @property int $id
 * @property int $product_id
 * @property string $barcode
 * @property string|float $pack_quantity
 * @property bool $is_primary
 */
class ProductBarcode extends Model
{
    protected $fillable = [
        'product_id',
        'barcode',
        'pack_quantity',
        'is_primary',
    ];

    protected $casts = [
        'pack_quantity' => 'decimal:3',
        'is_primary' => 'boolean',
    ];

    protected $attributes = [
        'pack_quantity' => 1,
        'is_primary' => false,
    ];

    public static function normalize(?string $barcode): ?string
    {
        $barcode = $barcode === null ? null : trim($barcode);

        return $barcode === '' ? null : $barcode;
    }

    protected static function booted(): void
    {
        static::saving(function (ProductBarcode $barcode) {
            $barcode->barcode = (string) self::normalize($barcode->barcode);
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
