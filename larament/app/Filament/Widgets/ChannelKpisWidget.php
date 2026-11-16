<?php

namespace App\Filament\Widgets;

use App\Services\ChannelPerformanceReportService;
use App\Services\Reports\Channels\ChannelReportFilters;
use Filament\Support\Enums\IconPosition;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The channels report's six headline figures, each with its change against
 * the comparison period.
 */
class ChannelKpisWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $service = app(ChannelPerformanceReportService::class);
        $filters = ChannelReportFilters::fromArray($this->pageFilters);
        $report = $service->report($filters);
        $now = $report['totals'];
        $before = $report['previous_totals'];
        $daily = $service->daily($filters);
        $sparkSales = array_column($daily, 'net_sales');
        $sparkOrders = array_map('floatval', array_column($daily, 'orders'));
        $sparkAvg = array_map(fn (array $day) => $day['orders'] > 0 ? round($day['net_sales'] / $day['orders'], 2) : 0.0, array_values($daily));

        return [
            $this->stat('صافي المبيعات', self::money($now['net_sales']), $before ? ChannelPerformanceReportService::change($now['net_sales'], $before['net_sales']) : null, '%', higherIsBetter: true,
                fallback: 'بعد المرتجعات '.self::money($now['refunds']))
                ->chart($sparkSales),

            $this->stat('الطلبات', number_format($now['orders']), $before ? ChannelPerformanceReportService::change($now['orders'], $before['orders']) : null, '%', higherIsBetter: true,
                fallback: 'طلب مكتمل')
                ->chart($sparkOrders),

            $this->stat('متوسط الطلب', self::money($now['avg_order']), $before ? ChannelPerformanceReportService::change($now['avg_order'], $before['avg_order']) : null, '%', higherIsBetter: true,
                fallback: 'صافي المبيعات ÷ الطلبات')
                ->chart($sparkAvg),

            $this->stat('هامش الربح', self::percent($now['margin']), $before ? round($now['margin'] - $before['margin'], 1) : null, ' نقطة', higherIsBetter: true,
                fallback: 'مجمل الربح '.self::money($now['net_profit'])),

            $this->stat('نسبة المرتجع', self::percent($now['refund_rate']), $before ? round($now['refund_rate'] - $before['refund_rate'], 2) : null, ' نقطة', higherIsBetter: false,
                fallback: number_format($now['refunds_count']).' مرتجع بـ '.self::money($now['refunds'])),

            $this->stat('نسبة الإلغاء', self::percent($now['cancel_rate']), $before ? round($now['cancel_rate'] - $before['cancel_rate'], 1) : null, ' نقطة', higherIsBetter: false,
                fallback: number_format($now['cancelled']).' طلب ملغي'),
        ];
    }

    /**
     * A stat whose description is the change against the comparison period,
     * green when it went the good way; or the fallback text when there is
     * nothing to compare with.
     */
    private function stat(string $label, string $value, ?float $change, string $unit, bool $higherIsBetter, string $fallback): Stat
    {
        $stat = Stat::make($label, $value);

        if ($change === null) {
            return $stat->description($fallback)->color('gray');
        }

        $good = $change == 0 ? null : (($change > 0) === $higherIsBetter);
        $sign = $change > 0 ? '+' : '';

        return $stat
            ->description($sign.number_format($change, abs($change) < 1 && $unit !== '%' ? 2 : 1).$unit.' عن '.$this->comparisonWord())
            ->descriptionIcon($change >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down', IconPosition::Before)
            ->color($good === null ? 'gray' : ($good ? 'success' : 'danger'));
    }

    private function comparisonWord(): string
    {
        return ChannelReportFilters::fromArray($this->pageFilters)->compare === ChannelReportFilters::COMPARE_LAST_YEAR
            ? 'السنة اللي فاتت'
            : 'الفترة السابقة';
    }

    public static function money(float $value): string
    {
        return number_format($value, 2).' ج.م';
    }

    public static function percent(float $value): string
    {
        return number_format($value, abs($value) < 1 && $value != 0 ? 2 : 1).'%';
    }
}
