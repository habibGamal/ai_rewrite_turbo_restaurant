<?php

namespace App\Filament\Exports;

use App\Models\User;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class CashierCommissionTableExporter extends Exporter
{
    protected static ?string $model = User::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('name')
                ->label('اسم الكاشير'),

            ExportColumn::make('orders_count')
                ->label('الطلبات المنشأة (عدد)')
                ->state(fn (User $record): int => (int) ($record->getAttribute('orders_count') ?? 0)),

            ExportColumn::make('orders_total_value')
                ->label('إجمالي قيمة الطلبات (ج.م)')
                ->state(fn (User $record): string => number_format((float) ($record->getAttribute('orders_total_value') ?? 0), 2)),

            ExportColumn::make('orders_without_discount_count')
                ->label('طلبات بدون خصم (عدد)')
                ->state(fn (User $record): int => (int) ($record->getAttribute('orders_without_discount_count') ?? 0)),

            ExportColumn::make('orders_without_discount_value')
                ->label('طلبات بدون خصم (قيمة ج.م)')
                ->state(fn (User $record): string => number_format((float) ($record->getAttribute('orders_without_discount_value') ?? 0), 2)),

            ExportColumn::make('orders_with_discount_count')
                ->label('طلبات بخصم (عدد)')
                ->state(fn (User $record): int => (int) ($record->getAttribute('orders_with_discount_count') ?? 0)),

            ExportColumn::make('orders_with_discount_value')
                ->label('طلبات بخصم (قيمة ج.م)')
                ->state(fn (User $record): string => number_format((float) ($record->getAttribute('orders_with_discount_value') ?? 0), 2)),

            ExportColumn::make('total_discount_amount')
                ->label('إجمالي الخصم الممنوح (ج.م)')
                ->state(fn (User $record): string => number_format((float) ($record->getAttribute('total_discount_amount') ?? 0), 2)),

            ExportColumn::make('items_added_count')
                ->label('الأصناف المضافة (عدد)')
                ->state(fn (User $record): float => (float) ($record->getAttribute('items_added_count') ?? 0)),

            ExportColumn::make('items_added_value')
                ->label('قيمة الأصناف المضافة (ج.م)')
                ->state(fn (User $record): string => number_format((float) ($record->getAttribute('items_added_value') ?? 0), 2)),

            ExportColumn::make('items_removed_count')
                ->label('الأصناف المحذوفة (عدد)')
                ->state(fn (User $record): float => (float) ($record->getAttribute('items_removed_count') ?? 0)),

            ExportColumn::make('items_removed_value')
                ->label('قيمة الأصناف المحذوفة (ج.م)')
                ->state(fn (User $record): string => number_format((float) ($record->getAttribute('items_removed_value') ?? 0), 2)),

            ExportColumn::make('net_items_value')
                ->label('صافي قيمة التعديلات (ج.م)')
                ->state(fn (User $record): string => number_format((float) ($record->getAttribute('net_items_value') ?? 0), 2)),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'تم إكمال تصدير تقرير عمولات وأداء الكاشير وتم تصدير '.number_format($export->successful_rows).' '.($export->successful_rows == 1 ? 'كاشير' : 'كاشير').'.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' فشل في تصدير '.number_format($failedRowsCount).' '.($failedRowsCount == 1 ? 'كاشير' : 'كاشير').'.';
        }

        return $body;
    }
}
