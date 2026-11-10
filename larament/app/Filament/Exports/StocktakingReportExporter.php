<?php

namespace App\Filament\Exports;

use App\Models\StocktakingItem;

class StocktakingReportExporter extends InventoryDocumentReportExporter
{
    protected static ?string $model = StocktakingItem::class;

    protected const COLUMNS = [
        'document_id' => ['رقم الجرد', 'number'],
        'business_date' => ['تاريخ العمل', 'date'],
        'product_name' => ['المنتج', 'text'],
        'warehouse_name' => ['المخزن', 'text'],
        'expected_quantity' => ['المتوقع عند التأكيد', 'quantity'],
        'actual_quantity' => ['الفعلي', 'quantity'],
        'variance_quantity' => ['فرق الكمية', 'quantity'],
        'variance_value' => ['قيمة الفرق (ج.م)', 'money'],
    ];
}
