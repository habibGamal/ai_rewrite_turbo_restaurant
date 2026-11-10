<?php

namespace App\Filament\Exports;

use App\Enums\InventoryMovementOperation;
use App\Models\InventoryItemMovement;
use App\Models\User;
use App\Services\Reports\Inventory\ItemLedgerReportService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class ItemLedgerReportExporter extends Exporter
{
    protected static ?string $model = InventoryItemMovement::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('created_at')
                ->label('التاريخ')
                ->state(fn (InventoryItemMovement $record): string => $record->created_at ? $record->created_at->format('Y-m-d H:i') : ''),

            ExportColumn::make('product.name')
                ->label('الصنف'),

            ExportColumn::make('warehouse.name')
                ->label('المخزن'),

            ExportColumn::make('document_type')
                ->label('نوع المستند')
                ->state(fn (InventoryItemMovement $record): string => ItemLedgerReportService::documentTypeLabel($record->referenceable_type, $record->reason)),

            ExportColumn::make('document_number')
                ->label('رقم المستند')
                ->state(fn (InventoryItemMovement $record): string => $record->referenceable_id !== null ? (string) $record->referenceable_id : '—'),

            ExportColumn::make('in_quantity')
                ->label('وارد')
                ->state(fn (InventoryItemMovement $record): float => $record->operation === InventoryMovementOperation::IN ? round((float) $record->quantity, 3) : 0.0),

            ExportColumn::make('out_quantity')
                ->label('صادر')
                ->state(fn (InventoryItemMovement $record): float => $record->operation === InventoryMovementOperation::OUT ? round((float) $record->quantity, 3) : 0.0),

            ExportColumn::make('running_balance')
                ->label('الرصيد التراكمي')
                ->state(fn (InventoryItemMovement $record): float => ItemLedgerReportService::runningBalanceForMovement($record)),

            ExportColumn::make('unit_cost')
                ->label('تكلفة الوحدة')
                ->state(fn (InventoryItemMovement $record): string => number_format((float) $record->unit_cost, 2)),

            ExportColumn::make('total_cost')
                ->label('القيمة')
                ->state(fn (InventoryItemMovement $record): string => number_format((float) $record->total_cost, 2)),

            ExportColumn::make('user.name')
                ->label('المستخدم')
                ->state(fn (InventoryItemMovement $record): string => $record->user instanceof User ? $record->user->name : '—'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'تم إكمال تصدير كشف حركة الصنف وتم تصدير '.number_format($export->successful_rows).' '.($export->successful_rows == 1 ? 'صف' : 'صفوف').'.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' فشل في تصدير '.number_format($failedRowsCount).' '.($failedRowsCount == 1 ? 'صف' : 'صفوف').'.';
        }

        return $body;
    }
}
