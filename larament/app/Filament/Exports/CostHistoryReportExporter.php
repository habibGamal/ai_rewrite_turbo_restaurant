<?php

namespace App\Filament\Exports;

use App\Models\ProductCostHistory;

class CostHistoryReportExporter extends InventoryDocumentReportExporter
{
    protected static ?string $model = ProductCostHistory::class;

    protected const COLUMNS = [
        'report_date' => ['تاريخ العمل / التعديل اليدوي', 'date'],
        'created_at' => ['وقت تسجيل التغيير', 'datetime'],
        'product_name' => ['المنتج', 'text'],
        'old_cost' => ['التكلفة السابقة (ج.م)', 'cost'],
        'new_cost' => ['التكلفة الجديدة (ج.م)', 'cost'],
        'method' => ['طريقة التكلفة', 'method'],
        'document_type' => ['نوع المستند', 'text'],
        'document_id' => ['رقم المستند', 'number'],
        'warehouse_name' => ['المخزن', 'text'],
        'user_name' => ['المستخدم', 'text'],
    ];
}
