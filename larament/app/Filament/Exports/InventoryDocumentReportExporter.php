<?php

namespace App\Filament\Exports;

use App\Costing\CostingMethod;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

abstract class InventoryDocumentReportExporter extends Exporter
{
    protected const COLUMNS = [];

    /** @return array<string, array{string, string}> */
    public static function columnDefinitions(): array
    {
        return static::COLUMNS;
    }

    public static function getColumns(): array
    {
        $columns = [];
        foreach (static::columnDefinitions() as $name => [$label, $type]) {
            $column = ExportColumn::make($name)->label($label);
            if (in_array($type, ['quantity', 'money', 'cost', 'percent'], true)) {
                $column->formatStateUsing(fn ($state) => $state === null ? null : (float) $state);
            }
            if ($type === 'method') {
                $column->formatStateUsing(fn (?CostingMethod $state) => $state?->label());
            }
            if ($type === 'date') {
                $column->formatStateUsing(fn ($state) => $state ? \Carbon\Carbon::parse($state)->toDateString() : null);
            }
            if ($type === 'datetime') {
                $column->formatStateUsing(fn ($state) => $state ? \Carbon\Carbon::parse($state)->toDateTimeString() : null);
            }
            $columns[] = $column;
        }

        return $columns;
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'تم تصدير '.number_format($export->successful_rows).' صفوف. عدد الصفوف التي تعذر تصديرها: '.number_format($export->getFailedRowsCount()).'.';
    }
}
