<?php

use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\ReturnPurchaseInvoice;
use App\Models\ReturnPurchaseInvoiceItem;
use App\Models\Supplier;
use App\Sync\Builders\PurchaseBuilder;
use App\Sync\DocumentType;
use Carbon\Carbon;
use Illuminate\Support\Str;

it('builds a purchase invoice snapshot', function () {
    pairPlatform();

    $supplier = Supplier::factory()->create();
    $product = Product::factory()->create();

    $invoice = PurchaseInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'business_date' => '2026-10-29',
        'closed_at' => Carbon::parse('2026-10-29 12:00:00', 'Africa/Cairo'),
        'total' => 500.00,
    ]);

    $item = PurchaseInvoiceItem::factory()->create([
        'purchase_invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'quantity' => 10.0,
        'price' => 50.00,
        'total' => 500.00,
    ]);

    $builder = new PurchaseBuilder(DocumentType::PurchaseInvoice);
    $data = $builder->build($invoice->uuid);

    expect($data)->toEqual([
        'business_date' => '2026-10-29',
        'closed_at' => '2026-10-29T12:00:00+03:00',
        'supplier_uuid' => $supplier->uuid,
        'total' => '500.00',
        'items' => [
            [
                'product_uuid' => $product->uuid,
                'quantity' => '10.000',
                'price' => '50.00',
                'total' => '500.00',
            ],
        ],
    ]);
});

it('builds a purchase return snapshot', function () {
    pairPlatform();

    $supplier = Supplier::factory()->create();
    $product = Product::factory()->create();

    $return = ReturnPurchaseInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'business_date' => '2026-10-29',
        'closed_at' => Carbon::parse('2026-10-29 14:00:00', 'Africa/Cairo'),
        'total' => 150.00,
    ]);

    $item = ReturnPurchaseInvoiceItem::factory()->create([
        'return_purchase_invoice_id' => $return->id,
        'product_id' => $product->id,
        'quantity' => 3.0,
        'price' => 50.00,
        'total' => 150.00,
    ]);

    $builder = new PurchaseBuilder(DocumentType::PurchaseReturn);
    $data = $builder->build($return->uuid);

    expect($data)->toEqual([
        'business_date' => '2026-10-29',
        'closed_at' => '2026-10-29T14:00:00+03:00',
        'supplier_uuid' => $supplier->uuid,
        'total' => '150.00',
        'items' => [
            [
                'product_uuid' => $product->uuid,
                'quantity' => '3.000',
                'price' => '50.00',
                'total' => '150.00',
            ],
        ],
    ]);
});

it('returns null for an unknown purchase invoice or return uuid', function () {
    $invoiceBuilder = new PurchaseBuilder(DocumentType::PurchaseInvoice);
    $returnBuilder = new PurchaseBuilder(DocumentType::PurchaseReturn);

    expect($invoiceBuilder->build((string) Str::uuid()))->toBeNull()
        ->and($returnBuilder->build((string) Str::uuid()))->toBeNull();
});
