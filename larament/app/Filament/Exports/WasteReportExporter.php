<?php

namespace App\Filament\Exports;

use App\Models\InventoryItemMovement;

class WasteReportExporter extends InventoryDocumentReportExporter
{
    protected static ?string $model = InventoryItemMovement::class;

    protected const COLUMNS = [
        'product_name' => ['المنتج', 'text'],
        'warehouse_name' => ['المخزن', 'text'],
        'waste_reason' => ['سبب الهالك / ملاحظات', 'text'],
        'quantity' => ['الكمية', 'quantity'],
        'value' => ['القيمة (ج.م)', 'money'],
    ];
}
