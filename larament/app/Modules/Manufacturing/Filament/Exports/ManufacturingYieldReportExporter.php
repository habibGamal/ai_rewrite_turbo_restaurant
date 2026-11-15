<?php

namespace App\Modules\Manufacturing\Filament\Exports;

use App\Filament\Exports\InventoryDocumentReportExporter;

use App\Models\ManufacturingOrderOutput;

class ManufacturingYieldReportExporter extends InventoryDocumentReportExporter
{
    protected static ?string $model = ManufacturingOrderOutput::class;

    protected const COLUMNS = [
        'product_name' => ['المنتج الناتج', 'text'],
        'warehouse_name' => ['مخزن الاستلام', 'text'],
        'order_count' => ['عدد أوامر التصنيع', 'number'],
        'expected_quantity' => ['الكمية المتوقعة', 'quantity'],
        'actual_quantity' => ['الكمية الفعلية', 'quantity'],
        'variance_quantity' => ['فرق الكمية', 'quantity'],
        'variance_value' => ['قيمة الفرق (ج.م)', 'money'],
        'variance_percentage' => ['نسبة الفرق الإجمالية', 'percent'],
        'average_variance_percentage' => ['متوسط نسبة الفرق بين الأوامر', 'percent'],
    ];
}
