<?php

namespace App\Services\Products;

use App\Enums\ProductType;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Services\SettingsService;

/**
 * The one place that turns a scanned code into a product and a quantity.
 * Used by the POS scanner, stocktaking and purchase invoices.
 *
 * Order of resolution:
 *  1. Exact match in product_barcodes. Quantity is the barcode's pack_quantity.
 *  2. Scale label: a 13-digit code starting with the scale prefix setting.
 *     Layout [PLU: 7 digits incl. prefix][weight in grams: 5 digits][check digit];
 *     the PLU is looked up as a barcode and the quantity is the weight in kg.
 *
 * Inactive products (products.is_active = false) are skipped.
 *
 * Trying the exact match first means a normal EAN that happens to start with the
 * scale prefix (e.g. "23...") still finds its product.
 */
class ProductLookupService
{
    private const SCALE_CODE_LENGTH = 13;

    private const SCALE_PLU_LENGTH = 7;

    private const SCALE_WEIGHT_LENGTH = 5;

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @param  list<ProductType|string>|null  $types  limit to these product types (null = any)
     * @param  bool  $includeInactive  stock documents (stocktaking, transfers, waste…) still handle retired SKUs
     */
    public function lookup(?string $code, ?array $types = null, bool $includeInactive = false): ?ProductLookupResult
    {
        $code = ProductBarcode::normalize($code);
        if ($code === null) {
            return null;
        }

        $match = $this->findBarcode($code, $types, $includeInactive);
        if ($match !== null) {
            $pack = (float) $match->pack_quantity;

            return new ProductLookupResult(
                product: $match->product,
                quantity: $pack > 0 ? $pack : 1.0,
                source: ProductLookupResult::SOURCE_BARCODE,
                barcode: $code,
                packQuantity: $pack > 0 ? $pack : 1.0,
            );
        }

        return $this->lookupScale($code, $types, $includeInactive);
    }

    /**
     * @param  list<ProductType|string>|null  $types
     */
    private function lookupScale(string $code, ?array $types, bool $includeInactive = false): ?ProductLookupResult
    {
        $prefix = (string) $this->settings->getScaleBarcodePrefix();

        if ($prefix === ''
            || strlen($code) !== self::SCALE_CODE_LENGTH
            || ! ctype_digit($code)
            || ! str_starts_with($code, $prefix)) {
            return null;
        }

        $plu = substr($code, 0, self::SCALE_PLU_LENGTH);
        $match = $this->findBarcode($plu, $types, $includeInactive);
        if ($match === null) {
            return null;
        }

        $weightKg = ((int) substr($code, self::SCALE_PLU_LENGTH, self::SCALE_WEIGHT_LENGTH)) / 1000;

        return new ProductLookupResult(
            product: $match->product,
            // A label with zero weight adds one unit, as the old client-side scanner did.
            quantity: $weightKg > 0 ? $weightKg : 1.0,
            source: ProductLookupResult::SOURCE_SCALE,
            barcode: $plu,
            weightKg: $weightKg,
        );
    }

    /**
     * @param  list<ProductType|string>|null  $types
     */
    private function findBarcode(string $barcode, ?array $types, bool $includeInactive = false): ?ProductBarcode
    {
        // Inactive products (e.g. a retired variant SKU) are never sold from a scan;
        // stock documents ask for them explicitly.
        return ProductBarcode::query()
            ->where('barcode', $barcode)
            ->whereHas('product', fn ($product) => $product
                ->when(! $includeInactive, fn ($product) => $product->where('is_active', true))
                ->when($types !== null, fn ($product) => $product->whereIn(
                    'type',
                    array_map(fn ($type) => $type instanceof ProductType ? $type->value : $type, $types ?? []),
                )))
            ->with('product')
            ->first();
    }
}
