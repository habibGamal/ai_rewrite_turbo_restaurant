<?php

namespace App\Filament\Widgets;

use App\Services\CustomersPerformanceReportService;
use Filament\Support\Enums\IconPosition;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CustomersPerformanceStatsWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        [$defaultStart, $defaultEnd] = CustomersPerformanceReportService::defaultPeriod();
        $kpis = app(CustomersPerformanceReportService::class)->getKpis(
            $this->pageFilters['startDate'] ?? $defaultStart,
            $this->pageFilters['endDate'] ?? $defaultEnd,
        );
        $current = $kpis['current'];
        $previous = $kpis['previous'];

        return [
            $this->stat('العملاء المشترون', number_format($current['customers']), $current['customers'], $previous['customers'])
                ->icon('heroicon-o-users'),

            $this->stat('عملاء جدد', number_format($current['new_customers']), $current['new_customers'], $previous['new_customers'])
                ->icon('heroicon-o-user-plus'),

            $this->stat('مبيعات العملاء (صافي)', $this->money($current['sales']), $current['sales'], $previous['sales'],
                sprintf('%s%% من إجمالي مبيعات المحل', number_format($current['sales_share'], 1)))
                ->icon('heroicon-o-banknotes'),

            $this->stat('صافي الربح', $this->money($current['profit']), $current['profit'], $previous['profit'],
                sprintf('هامش %s%%', number_format($current['margin'], 1)))
                ->icon('heroicon-o-chart-bar'),

            $this->stat('متوسط قيمة الطلب', $this->money($current['avg_order_value']), $current['avg_order_value'], $previous['avg_order_value'],
                sprintf('%s طلب', number_format($current['orders'])))
                ->icon('heroicon-o-calculator'),

            $this->stat('معدل العودة', number_format($current['return_rate'], 1).'%', $current['return_rate'], $previous['return_rate'],
                'عملاء طلبوا أكثر من مرة في الفترة', points: true)
                ->icon('heroicon-o-arrow-path'),
        ];
    }

    protected function getColumns(): int
    {
        return 3;
    }

    private function stat(string $label, string $value, float $current, float $previous, ?string $note = null, bool $points = false): Stat
    {
        if ($points) {
            $diff = $current - $previous;
            $change = sprintf('%s%s نقطة عن الفترة السابقة', $diff >= 0 ? '+' : '', number_format($diff, 1));
        } elseif ($previous > 0) {
            $diff = ($current - $previous) / $previous * 100;
            $change = sprintf('%s%s%% عن الفترة السابقة', $diff >= 0 ? '+' : '', number_format($diff, 1));
        } else {
            $diff = $current > 0 ? 1 : 0;
            $change = $current > 0 ? 'لا توجد بيانات للفترة السابقة' : 'بدون تغيير';
        }

        $color = abs($diff) < 0.05 ? 'gray' : ($diff > 0 ? 'success' : 'danger');

        return Stat::make($label, $value)
            ->description($note ? "{$note} · {$change}" : $change)
            ->descriptionIcon($diff >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down', IconPosition::Before)
            ->color($color);
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2).' ج.م';
    }
}
