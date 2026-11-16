<?php

namespace App\Filament\Exports;

use App\Models\Order;
use App\Models\OrderItemChange;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class CashierItemChangesTableExporter extends Exporter
{
    protected static ?string $model = OrderItemChange::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('order_number')
                ->label('رقم الطلب')
                ->state(function (OrderItemChange $record): string {
                    $order = $record->order;

                    return '#'.($order instanceof Order ? ($order->order_number ?? $record->order_id) : $record->order_id);
                }),

            ExportColumn::make('product_name')
                ->label('اسم المنتج')
                ->state(fn (OrderItemChange $record): string => $record->product_name ?? '—'),

            ExportColumn::make('change_type')
                ->label('نوع الحركة')
                ->state(fn (OrderItemChange $record): string => match ($record->change_type) {
                    'added' => 'إضافة',
                    'removed' => 'حذف',
                    default => (float) $record->delta > 0 ? 'زيادة' : 'نقص',
                }),

            ExportColumn::make('old_quantity')
                ->label('الكمية السابقة')
                ->state(fn (OrderItemChange $record): string => number_format((float) ($record->old_quantity ?? 0), 2)),

            ExportColumn::make('new_quantity')
                ->label('الكمية الجديدة')
                ->state(fn (OrderItemChange $record): string => number_format((float) ($record->new_quantity ?? 0), 2)),

            ExportColumn::make('delta')
                ->label('الكمية المُعدلة')
                ->state(fn (OrderItemChange $record): string => ((float) $record->delta > 0 ? '+' : '').(float) $record->delta),

            ExportColumn::make('unit_price')
                ->label('سعر الوحدة (ج.م)')
                ->state(fn (OrderItemChange $record): string => number_format((float) ($record->unit_price ?? 0), 2)),

            ExportColumn::make('total_price')
                ->label('إجمالي القيمة (ج.م)')
                ->state(fn (OrderItemChange $record): string => ((float) $record->delta >= 0 ? '+' : '-').number_format((float) ($record->total_price ?? 0), 2)),

            ExportColumn::make('created_at')
                ->label('التاريخ والوقت')
                ->formatStateUsing(fn ($state) => $state?->format('Y-m-d H:i:s') ?? ''),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'تم إكمال تصدير سجل تعديلات الأصناف وتم تصدير '.number_format($export->successful_rows).' حركة.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' فشل في تصدير '.number_format($failedRowsCount).' حركة.';
        }

        return $body;
    }
}
