<?php

namespace App\Console\Commands;

use App\Services\Products\ProductBarcodeBackfillService;
use Illuminate\Console\Command;

class BackfillProductBarcodesCommand extends Command
{
    protected $signature = 'products:backfill-barcodes';

    protected $description = 'Copy products.barcode into product_barcodes and report barcodes shared by more than one product';

    public function handle(ProductBarcodeBackfillService $backfill): int
    {
        $result = $backfill->run();

        $this->info("Added {$result['inserted']} barcode(s); {$result['skipped_existing']} were already there.");

        if ($result['duplicates'] === []) {
            $this->info('No duplicate barcodes.');

            return self::SUCCESS;
        }

        $this->warn(count($result['duplicates']).' product(s) share a barcode with an older product. Only the older product scans; fix the others.');
        $this->table(
            ['Barcode', 'Kept product', 'Duplicate product'],
            array_map(fn ($row) => [
                $row['barcode'],
                "#{$row['kept_product_id']} {$row['kept_product_name']}",
                "#{$row['product_id']} {$row['product_name']}",
            ], $result['duplicates']),
        );

        if ($result['report_path'] !== null) {
            $this->line("Report: {$result['report_path']}");
        }

        return self::SUCCESS;
    }
}
