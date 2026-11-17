<?php

namespace App\Filament\Exports\Concerns;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Services\FeatureService;
use App\Services\ShiftsReportService;
use Filament\Actions\Exports\ExportColumn;
use Illuminate\Support\Collection;

/**
 * Exports of a shift / period report count only payments taken inside the report's shifts,
 * matching the report tables and cards. Options: `shift_ids` (list of IDs) or `scope`
 * ([startDate, endDate, shiftIds] as ShiftsReportService::shiftIdsQuery takes them).
 */
trait ScopedExportPayments
{
    /** @var array<string, list<int>> */
    private static array $scopeShiftIdsCache = [];

    /**
     * @param  array<string, mixed>  $options
     */
    protected static function scopedPayments(Order $record, array $options): Collection
    {
        $ids = self::scopeShiftIds($options);

        return $ids === null ? $record->payments : $record->payments->whereIn('shift_id', $ids);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<int>|null
     */
    private static function scopeShiftIds(array $options): ?array
    {
        if (isset($options['shift_ids'])) {
            return array_values(array_map('intval', (array) $options['shift_ids']));
        }

        if (! isset($options['scope']) || ! is_array($options['scope'])) {
            return null;
        }

        $key = md5((string) json_encode($options['scope']));

        return self::$scopeShiftIdsCache[$key] ??= app(ShiftsReportService::class)
            ->shiftIdsQuery(...array_values($options['scope']))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<ExportColumn>
     */
    protected static function scopedPaymentExportColumns(): array
    {
        if (! app(FeatureService::class)->deferredPaymentEnabled()) {
            return [
                ExportColumn::make('paid_in_scope')
                    ->label('المدفوع في نطاق التقرير (جنيه)')
                    ->state(fn (Order $record, array $options): string => number_format((float) self::scopedPayments($record, $options)->sum('amount'), 2, '.', '')),
            ];
        }

        return [
            ExportColumn::make('payment_status')
                ->label('حالة الدفع الآن')
                ->formatStateUsing(fn (mixed $state): string => $state instanceof PaymentStatus ? $state->label() : (string) $state),

            ExportColumn::make('paid_in_scope')
                ->label('المدفوع في نطاق التقرير (جنيه)')
                ->state(fn (Order $record, array $options): string => number_format((float) self::scopedPayments($record, $options)->sum('amount'), 2, '.', '')),

            ExportColumn::make('uncollected_in_scope')
                ->label('آجل غير محصل (جنيه)')
                ->state(function (Order $record, array $options): string {
                    if ($record->status !== OrderStatus::COMPLETED) {
                        return '0.00';
                    }

                    $remaining = max(0.0, (float) $record->total - (float) self::scopedPayments($record, $options)->sum('amount'));

                    return number_format($remaining, 2, '.', '');
                }),
        ];
    }
}
