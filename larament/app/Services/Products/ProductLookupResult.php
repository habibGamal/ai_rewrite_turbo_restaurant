<?php

namespace App\Services\Products;

use App\Models\Product;

final class ProductLookupResult
{
    public const SOURCE_BARCODE = 'barcode';

    public const SOURCE_SCALE = 'scale';

    public function __construct(
        public readonly Product $product,
        /** Base units this scan adds: pack_quantity for a barcode, the weight in kg for a scale label. */
        public readonly float $quantity,
        public readonly string $source,
        public readonly string $barcode,
        public readonly float $packQuantity = 1.0,
        public readonly ?float $weightKg = null,
    ) {}

    public function isScale(): bool
    {
        return $this->source === self::SOURCE_SCALE;
    }

    /**
     * @return array{product_id: int, product_name: string, quantity: float, source: string, barcode: string, pack_quantity: float, weight_kg: float|null}
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'quantity' => $this->quantity,
            'source' => $this->source,
            'barcode' => $this->barcode,
            'pack_quantity' => $this->packQuantity,
            'weight_kg' => $this->weightKg,
        ];
    }
}
