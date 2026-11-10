<?php

namespace App\Filament\Exports;

use App\Models\InventoryItemMovement;

class StockIssuesReportExporter extends InventoryDocumentReportExporter
{
    protected static ?string $model = InventoryItemMovement::class;

    protected const COLUMNS = [
        'destination' => ['جهة الصرف', 'text'],
        'warehouse_name' => ['المخزن', 'text'],
        'user_name' => ['المستخدم', 'text'],
        'value' => ['إجمالي التكلفة (ج.م)', 'money'],
    ];
}
