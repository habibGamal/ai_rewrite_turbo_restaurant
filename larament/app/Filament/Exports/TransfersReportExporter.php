<?php

namespace App\Filament\Exports;

use App\Models\InventoryItemMovement;

class TransfersReportExporter extends InventoryDocumentReportExporter
{
    protected static ?string $model = InventoryItemMovement::class;

    protected const COLUMNS = [
        'from_warehouse_name' => ['من مخزن', 'text'],
        'to_warehouse_name' => ['إلى مخزن', 'text'],
        'product_name' => ['المنتج', 'text'],
        'quantity' => ['الكمية', 'quantity'],
        'value' => ['القيمة (ج.م)', 'money'],
    ];
}
