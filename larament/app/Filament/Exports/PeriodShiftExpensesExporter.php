<?php

namespace App\Filament\Exports;

use App\Models\ExpenceType;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class PeriodShiftExpensesExporter extends Exporter
{
    protected static ?string $model = ExpenceType::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('name')
                ->label('نوع المصروف'),

            ExportColumn::make('expense_count')
                ->label('عدد المصروفات')
                ->state(function ($record) {
                    // This will be populated by the widget's query
                    return $record->expense_count ?? 0;
                }),

            ExportColumn::make('total_amount')
                ->label('الإجمالي (جنيه)')
                ->state(function ($record) {
                    // This will be populated by the widget's query
                    return number_format($record->total_amount ?? 0, 2);
                }),

            ExportColumn::make('average_amount')
                ->label('متوسط المبلغ (جنيه)')
                ->state(function ($record) {
                    $count = $record->expense_count ?? 0;
                    $total = $record->total_amount ?? 0;
                    $average = $count > 0 ? $total / $count : 0;
                    return number_format($average, 2);
                }),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'تم إكمال تصدير ملخص مصروفات فترة الشفتات وتم تصدير ' . number_format($export->successful_rows) . ' ' . ($export->successful_rows == 1 ? 'نوع مصروف' : 'نوع مصروف') . '.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' فشل في تصدير ' . number_format($failedRowsCount) . ' ' . ($failedRowsCount == 1 ? 'نوع مصروف' : 'نوع مصروف') . '.';
        }

        return $body;
    }
}
