<?php

namespace App\Filament\Widgets\Concerns;

use App\Services\FeatureService;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The «إيرادات أخرى» stat: misc shift revenues (revenue rows, never orders).
 * Shown while the REVENUES feature is on, or when old revenues exist.
 */
trait OtherRevenuesStats
{
    /**
     * @param  array<string, mixed>  $stats  output of ShiftsReportService::calculateShiftStats / calculatePeriodStats
     * @return list<Stat>
     */
    protected function otherRevenuesStats(array $stats): array
    {
        $total = (float) ($stats['revenues'] ?? 0);

        if (! app(FeatureService::class)->revenuesEnabled() && abs($total) < 0.0001) {
            return [];
        }

        return [
            Stat::make('إيرادات أخرى', number_format($total, 2).' جنيه')
                ->description('نقدي '.number_format((float) ($stats['cashRevenues'] ?? 0), 2).' · فيزا '.number_format((float) ($stats['cardRevenues'] ?? 0), 2))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),
        ];
    }
}
