<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductBarcode;
use Illuminate\Support\Facades\Log;

/**
 * Keeps product_barcodes in step with products.barcode, which stays as the
 * product's primary barcode so imports, sync and old screens keep working.
 */
class ProductBarcodeService
{
    /**
     * The product that already owns this barcode, other than $exceptProductId.
     */
    public function ownerOf(?string $barcode, ?int $exceptProductId = null): ?Product
    {
        $barcode = ProductBarcode::normalize($barcode);
        if ($barcode === null) {
            return null;
        }

        $row = ProductBarcode::query()
            ->where('barcode', $barcode)
            ->when($exceptProductId !== null, fn ($q) => $q->where('product_id', '!=', $exceptProductId))
            ->with('product')
            ->first();

        return $row?->product;
    }

    public function syncPrimary(Product $product): void
    {
        $barcode = ProductBarcode::normalize($product->barcode);

        $current = ProductBarcode::query()
            ->where('product_id', $product->id)
            ->where('is_primary', true)
            ->first();

        if ($current !== null && $current->barcode === $barcode) {
            return;
        }

        if ($barcode === null) {
            $current?->delete();

            return;
        }

        $existing = ProductBarcode::query()->where('barcode', $barcode)->first();

        if ($existing !== null && $existing->product_id !== $product->id) {
            // Form validation stops this; imports and raw updates can still get here.
            Log::warning('Barcode already belongs to another product; primary barcode not changed', [
                'barcode' => $barcode,
                'product_id' => $product->id,
                'owner_product_id' => $existing->product_id,
            ]);

            return;
        }

        $current?->delete();

        if ($existing !== null) {
            // The new primary was one of this product's extra barcodes: promote it.
            // A primary barcode is always one unit, even if it was a pack code.
            $existing->update(['is_primary' => true, 'pack_quantity' => 1]);

            return;
        }

        ProductBarcode::create([
            'product_id' => $product->id,
            'barcode' => $barcode,
            'pack_quantity' => 1,
            'is_primary' => true,
        ]);
    }
}
