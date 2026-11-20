<?php

namespace App\Services\Products;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Copies products.barcode into product_barcodes as each product's primary barcode.
 *
 * The oldest product (lowest id) keeps a barcode that several products share; the
 * others are listed in a CSV report so the shop can fix them. products.barcode is
 * never changed, so nothing is lost. Safe to run more than once.
 */
class ProductBarcodeBackfillService
{
    /**
     * @return array{inserted: int, skipped_existing: int, duplicates: list<array{barcode: string, kept_product_id: int, kept_product_name: string, product_id: int, product_name: string}>, report_path: string|null}
     */
    public function run(bool $writeReport = true): array
    {
        $now = Carbon::now();
        $inserted = 0;
        $skippedExisting = 0;
        $duplicates = [];

        /** @var array<string, array{id: int, name: string}> $owners barcode => owning product */
        $owners = DB::table('product_barcodes')
            ->join('products', 'products.id', '=', 'product_barcodes.product_id')
            ->get(['product_barcodes.barcode', 'products.id', 'products.name'])
            ->mapWithKeys(fn ($row) => [(string) $row->barcode => ['id' => (int) $row->id, 'name' => (string) $row->name]])
            ->all();

        $productsWithPrimary = DB::table('product_barcodes')
            ->where('is_primary', true)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->flip()
            ->all();

        DB::table('products')
            ->whereNotNull('barcode')
            ->orderBy('id')
            ->select(['id', 'name', 'barcode'])
            ->chunk(500, function ($products) use (&$owners, &$productsWithPrimary, &$inserted, &$skippedExisting, &$duplicates, $now) {
                $rows = [];

                foreach ($products as $product) {
                    $barcode = trim((string) $product->barcode);
                    if ($barcode === '') {
                        continue;
                    }

                    $productId = (int) $product->id;
                    $owner = $owners[$barcode] ?? null;

                    if ($owner !== null) {
                        if ($owner['id'] === $productId) {
                            $skippedExisting++;
                        } else {
                            $duplicates[] = [
                                'barcode' => $barcode,
                                'kept_product_id' => $owner['id'],
                                'kept_product_name' => $owner['name'],
                                'product_id' => $productId,
                                'product_name' => (string) $product->name,
                            ];
                        }

                        continue;
                    }

                    $rows[] = [
                        'product_id' => $productId,
                        'barcode' => $barcode,
                        'pack_quantity' => 1,
                        'is_primary' => ! isset($productsWithPrimary[$productId]),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $owners[$barcode] = ['id' => $productId, 'name' => (string) $product->name];
                    $productsWithPrimary[$productId] = true;
                }

                if ($rows !== []) {
                    DB::table('product_barcodes')->insert($rows);
                    $inserted += count($rows);
                }
            });

        $reportPath = null;
        if ($duplicates !== []) {
            Log::warning('Product barcode backfill found duplicate barcodes', [
                'count' => count($duplicates),
                'duplicates' => $duplicates,
            ]);

            if ($writeReport) {
                $reportPath = $this->writeReport($duplicates, $now);
            }
        }

        return [
            'inserted' => $inserted,
            'skipped_existing' => $skippedExisting,
            'duplicates' => $duplicates,
            'report_path' => $reportPath,
        ];
    }

    /**
     * @param  list<array{barcode: string, kept_product_id: int, kept_product_name: string, product_id: int, product_name: string}>  $duplicates
     */
    private function writeReport(array $duplicates, Carbon $now): ?string
    {
        try {
            $dir = storage_path('app/reports');
            File::ensureDirectoryExists($dir);
            $path = $dir.'/barcode-duplicates-'.$now->format('Ymd_His').'.csv';

            $handle = fopen($path, 'w');
            if ($handle === false) {
                return null;
            }
            // UTF-8 BOM so Excel shows Arabic product names correctly.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['barcode', 'kept_product_id', 'kept_product_name', 'duplicate_product_id', 'duplicate_product_name']);
            foreach ($duplicates as $row) {
                fputcsv($handle, [$row['barcode'], $row['kept_product_id'], $row['kept_product_name'], $row['product_id'], $row['product_name']]);
            }
            fclose($handle);

            return $path;
        } catch (\Throwable $e) {
            Log::error('Could not write barcode duplicates report', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
