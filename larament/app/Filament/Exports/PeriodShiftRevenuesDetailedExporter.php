<?php

namespace App\Filament\Exports;

use App\Models\Revenue;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class PeriodShiftRevenuesDetailedExporter extends Exporter
{
    protected static ?string $model = Revenue::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('رقم الإيراد'),
            ExportColumn::make('revenueType.name')->label('بند الإيراد'),
            ExportColumn::make('amount')->label('المبلغ')->formatStateUsing(fn ($state) => (float) $state),
            ExportColumn::make('method')->label('طريقة الدفع')->formatStateUsing(fn ($state) => $state?->getLabel()),
            ExportColumn::make('notes')->label('الملاحظات'),
            ExportColumn::make('shift.id')->label('رقم الشفت'),
            ExportColumn::make('user.name')->label('بواسطة'),
            ExportColumn::make('created_at')->label('تاريخ الإنشاء')->formatStateUsing(fn ($state) => $state?->format('Y-m-d H:i:s')),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'تم تصدير '.number_format($export->successful_rows).' إيراد بنجاح';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' و فشل تصدير '.number_format($failedRowsCount).' إيراد.';
        } else {
            $body .= '.';
        }

        return $body;
    }

    public function getFileName(Export $export): string
    {
        return "period-shift-revenues-detailed-{$export->getKey()}.xlsx";
    }
}
