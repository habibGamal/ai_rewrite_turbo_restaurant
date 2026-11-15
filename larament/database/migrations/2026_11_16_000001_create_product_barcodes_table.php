<?php

use App\Services\Products\ProductBarcodeBackfillService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // One barcode points at exactly one product, across the whole catalogue.
            $table->string('barcode', 64)->unique();
            // How many base units one scan of this barcode adds (1 = single item, 12 = a case of 12).
            $table->decimal('pack_quantity', 10, 3)->default(1);
            // The primary barcode mirrors products.barcode; extra barcodes are not primary.
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['product_id', 'is_primary']);
        });

        // Copy products.barcode into the new table. Duplicates are written to a report, not lost.
        app(ProductBarcodeBackfillService::class)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('product_barcodes');
    }
};
