<?php

namespace App\Filament\Exports;

use App\Models\ManufacturingOrderInput;
use App\Models\ManufacturingOrderOutput;
use Filament\Actions\Exports\ExportColumn;

class ManufacturingYieldOrderExporter extends InventoryDocumentReportExporter
{
    protected static ?string $model = ManufacturingOrderOutput::class;

    protected const COLUMNS = [
        'document_id' => ['رقم أمر التصنيع', 'number'],
        'business_date' => ['تاريخ العمل', 'date'],
        'source_warehouse_name' => ['مخزن المدخلات', 'text'],
        'warehouse_name' => ['مخزن الاستلام', 'text'],
        'inputs' => ['المدخلات: الكمية الفعلية × تكلفة الوحدة = القيمة (ج.م)', 'inputs'],
        'product_name' => ['المنتج الناتج', 'text'],
        'expected_quantity' => ['الكمية المتوقعة', 'quantity'],
        'actual_quantity' => ['الكمية الفعلية', 'quantity'],
        'unit_cost' => ['تكلفة وحدة الناتج (ج.م)', 'cost'],
        'allocated_cost' => ['قيمة الناتج (ج.م)', 'money'],
        'variance_quantity' => ['فرق الكمية', 'quantity'],
        'variance_value' => ['قيمة الفرق (ج.م)', 'money'],
        'variance_percentage' => ['نسبة الفرق', 'percent'],
    ];

    public static function getColumns(): array
    {
        return array_map(function (ExportColumn $column): ExportColumn {
            if ($column->getName() === 'inputs') {
                $column->state(fn (ManufacturingOrderOutput $record) => self::inputDescription($record));
            }

            return $column;
        }, parent::getColumns());
    }

    public static function inputDescription(ManufacturingOrderOutput $record): string
    {
        return $record->order->inputs->map(fn (ManufacturingOrderInput $input): string => $input->product->name.': '.number_format((float) $input->actual_quantity, 3).' × '.number_format((float) $input->unit_cost, 4).' = '.number_format((float) $input->total_cost, 2)
        )->implode('؛ ');
    }
}
