<?php

namespace App\Filament\Exports;

use App\Models\Product;
use App\Services\Reports\StockReportService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class StockReportExporter extends Exporter
{
    protected static ?string $model = Product::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('name')
                ->label('المنتج'),

            ExportColumn::make('category.name')
                ->label('التصنيف'),

            ExportColumn::make('start_quantity')
                ->label('الكمية البدائية')
                ->formatStateUsing(fn ($state) => $state ?? 0),

            ExportColumn::make('incoming')
                ->label('كمية الوارد')
                ->formatStateUsing(fn ($state) => $state ?? 0),

            ExportColumn::make('transfer_in')
                ->label('تحويل وارد')
                ->formatStateUsing(fn ($state) => $state ?? 0),

            ExportColumn::make('manufacturing_in')
                ->label('تصنيع وارد')
                ->formatStateUsing(fn ($state) => $state ?? 0),

            ExportColumn::make('total_quantity')
                ->label('الكمية الكلية')
                ->state(fn ($record) => StockReportService::calculate($record)['total']),

            ExportColumn::make('sales')
                ->label('كمية المبيعات')
                ->formatStateUsing(fn ($state) => $state ?? 0),

            ExportColumn::make('return_waste')
                ->label('كمية الفاقد والمرتجع')
                ->formatStateUsing(fn ($state) => $state ?? 0),

            ExportColumn::make('transfer_out')
                ->label('تحويل صادر')
                ->formatStateUsing(fn ($state) => $state ?? 0),

            ExportColumn::make('manufacturing_out')
                ->label('تصنيع صادر')
                ->formatStateUsing(fn ($state) => $state ?? 0),

            ExportColumn::make('issue')
                ->label('صرف')
                ->formatStateUsing(fn ($state) => $state ?? 0),

            ExportColumn::make('total_consumed')
                ->label('الكمية الكلية المنصرفة')
                ->state(fn ($record) => StockReportService::calculate($record)['consumed']),

            ExportColumn::make('ideal_remaining')
                ->label('الكمية المتبقية المثالية')
                ->state(fn ($record) => StockReportService::calculate($record)['ideal']),

            ExportColumn::make('actual_remaining_quantity')
                ->label('الكمية المتبقية الفعلية')
                ->formatStateUsing(fn ($state) => $state ?? 0),

            ExportColumn::make('cost')
                ->label('التكلفة الحالية')
                ->state(fn ($record) => $record->cost ?? 0)
                ->formatStateUsing(fn ($state) => number_format((float) $state, 2)),

            ExportColumn::make('deviation')
                ->label('الانحراف')
                ->state(fn ($record) => StockReportService::calculate($record)['deviation']),

            ExportColumn::make('deviation_value')
                ->label('قيمة الانحراف')
                ->state(fn ($record) => StockReportService::calculate($record)['deviation_value'])
                ->formatStateUsing(fn ($state) => number_format((float) $state, 2)),

            ExportColumn::make('deviation_percentage')
                ->label('نسبة الانحراف')
                ->state(fn ($record) => StockReportService::calculate($record)['deviation_percentage'])
                ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 1) : '—'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'تم إكمال تصدير تقرير المخزون وتم تصدير '.number_format($export->successful_rows).' '.($export->successful_rows == 1 ? 'صف' : 'صفوف').'.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' فشل في تصدير '.number_format($failedRowsCount).' '.($failedRowsCount == 1 ? 'صف' : 'صفوف').'.';
        }

        return $body;
    }
}
