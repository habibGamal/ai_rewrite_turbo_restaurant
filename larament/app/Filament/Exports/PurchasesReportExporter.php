<?php

namespace App\Filament\Exports;

use App\Models\InventoryItemMovement;

class PurchasesReportExporter extends InventoryDocumentReportExporter
{
    protected static ?string $model = InventoryItemMovement::class;

    protected const COLUMNS = [
        'product_name' => ['المنتج', 'text'],
        'supplier_name' => ['المورد', 'text'],
        'warehouse_name' => ['المخزن', 'text'],
        'quantity' => ['الكمية', 'quantity'],
        'value' => ['القيمة (ج.م)', 'money'],
        'average_price' => ['متوسط سعر الشراء (ج.م)', 'cost'],
    ];
}
