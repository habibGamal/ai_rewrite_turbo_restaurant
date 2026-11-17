<?php

namespace App\Services\Products;

use App\Enums\OptionalFeature;
use App\Enums\SettingKey;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Setting;
use App\Services\FeatureService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/**
 * Shop barcodes numbered in a series (0001, 0002, ... or CL0001, ...), handed
 * out from the purchase invoice to products that have no barcode yet.
 *
 * The next number lives in settings (purchase_barcode_next_number) and only
 * moves forward. Numbers whose code is already taken by any product are
 * skipped, so editing the start number can never create a duplicate.
 */
class SequentialBarcodeService
{
    /** Stops a run that keeps hitting taken codes (e.g. a start number set into a used range). */
    private const MAX_SKIPS = 10_000;

    private const SCALE_CODE_LENGTH = 13;

    private ?string $scalePrefix = null;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly FeatureService $features,
    ) {}

    /**
     * The code a number becomes with the current prefix and digit count.
     */
    public function format(int $number): string
    {
        return $this->prefix().str_pad((string) $number, $this->digits(), '0', STR_PAD_LEFT);
    }

    /**
     * The next free code, without reserving it.
     */
    public function preview(): string
    {
        $this->scalePrefix = null;
        $number = $this->nextNumber();
        $skips = 0;
        while (! $this->isUsable($this->format($number)) && $skips++ < self::MAX_SKIPS) {
            $number++;
        }

        return $this->format($number);
    }

    /**
     * Give each product that has no barcode the next code in the series, in
     * the order given. Products that already have a barcode are left alone.
     *
     * @param  iterable<int>  $productIds
     * @return array<int, string> product id => the barcode it was given
     */
    public function assignMissing(iterable $productIds): array
    {
        $this->features->assertEnabled(OptionalFeature::PURCHASE_BARCODES);

        $ids = [];
        foreach ($productIds as $id) {
            $id = (int) $id;
            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $this->scalePrefix = null;

        return DB::transaction(function () use ($ids): array {
            // Lock the counter row so two invoices generating at once never get the same number.
            $counter = Setting::query()
                ->where('key', SettingKey::PURCHASE_BARCODE_NEXT_NUMBER->value)
                ->lockForUpdate()
                ->first();
            $number = $counter !== null && $counter->value !== '' && $counter->value !== null
                ? max(0, (int) $counter->value)
                : $this->nextNumber();

            $products = Product::query()
                ->whereIn('id', $ids)
                ->where(fn ($q) => $q->whereNull('barcode')->orWhere('barcode', ''))
                ->get()
                ->sortBy(fn (Product $product): int => (int) array_search($product->id, $ids, true));

            $assigned = [];
            foreach ($products as $product) {
                $skips = 0;
                while (! $this->isUsable($this->format($number))) {
                    if (++$skips > self::MAX_SKIPS) {
                        throw new \RuntimeException('تعذر إيجاد باركود متاح في المسلسل. راجع الرقم التالي في الإعدادات.');
                    }
                    $number++;
                }

                $code = $this->format($number);
                // The product observer copies this into product_barcodes.
                $product->barcode = $code;
                $product->save();

                $assigned[$product->id] = $code;
                $number++;
            }

            if ($assigned !== []) {
                $this->settings->set(SettingKey::PURCHASE_BARCODE_NEXT_NUMBER->value, (string) $number);
            }

            return $assigned;
        });
    }

    public function nextNumber(): int
    {
        return max(0, (int) $this->settings->get(
            SettingKey::PURCHASE_BARCODE_NEXT_NUMBER->value,
            SettingKey::PURCHASE_BARCODE_NEXT_NUMBER->defaultValue(),
        ));
    }

    private function prefix(): string
    {
        return trim((string) $this->settings->get(SettingKey::PURCHASE_BARCODE_PREFIX->value, ''));
    }

    private function digits(): int
    {
        $digits = (int) $this->settings->get(
            SettingKey::PURCHASE_BARCODE_DIGITS->value,
            SettingKey::PURCHASE_BARCODE_DIGITS->defaultValue(),
        );

        return min(12, max(1, $digits));
    }

    /**
     * Free, and not readable as a scale label (13 digits starting with the
     * scale prefix), which the POS would decode as a weighed item.
     */
    private function isUsable(string $code): bool
    {
        $scalePrefix = $this->scalePrefix ??= $this->settings->getScaleBarcodePrefix();
        if ($scalePrefix !== ''
            && strlen($code) === self::SCALE_CODE_LENGTH
            && ctype_digit($code)
            && str_starts_with($code, $scalePrefix)) {
            return false;
        }

        return ! ProductBarcode::query()->where('barcode', $code)->exists()
            && ! Product::query()->where('barcode', $code)->exists();
    }
}
