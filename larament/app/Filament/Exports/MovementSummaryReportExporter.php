<?php

namespace App\Filament\Exports;

use App\Models\InventoryItemMovement;
use App\Services\Reports\Inventory\MovementSummaryReportService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Fluent;

class MovementSummaryReportExporter extends Exporter
{
    protected static ?string $model = InventoryItemMovement::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('reason_label')
                ->label('نوع الحركة')
                ->state(fn (InventoryItemMovement $record, Exporter $exporter): string => (string) (self::summaryRow($record, $exporter->getOptions())->get('label') ?? '')),

            ExportColumn::make('operation')
                ->label('الاتجاه')
                ->state(fn (InventoryItemMovement $record, Exporter $exporter): string => (self::summaryRow($record, $exporter->getOptions())->get('operation') ?? '') === 'in' ? 'وارد' : 'صادر'),

            ExportColumn::make('quantity')
                ->label('إجمالي الكمية')
                ->state(fn (InventoryItemMovement $record, Exporter $exporter): float => (float) (self::summaryRow($record, $exporter->getOptions())->get('quantity') ?? 0.0)),

            ExportColumn::make('value')
                ->label('إجمالي القيمة')
                ->state(fn (InventoryItemMovement $record, Exporter $exporter): string => number_format((float) (self::summaryRow($record, $exporter->getOptions())->get('value') ?? 0.0), 2)),

            ExportColumn::make('documents_count')
                ->label('عدد المستندات')
                ->state(fn (InventoryItemMovement $record, Exporter $exporter): int => (int) (self::summaryRow($record, $exporter->getOptions())->get('documents_count') ?? 0)),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return Fluent<string, mixed>
     */
    private static function summaryRow(InventoryItemMovement $record, array $options): Fluent
    {
        $key = MovementSummaryReportService::reasonKeyById((int) $record->id) ?? 'purchase';
        $rows = MovementSummaryReportService::rows($options);

        return $rows->get($key) ?? new Fluent;
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'تم إكمال تصدير ملخص حركة المخزون وتم تصدير '.number_format($export->successful_rows).' '.($export->successful_rows == 1 ? 'صف' : 'صفوف').'.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' فشل في تصدير '.number_format($failedRowsCount).' '.($failedRowsCount == 1 ? 'صف' : 'صفوف').'.';
        }

        return $body;
    }
}
