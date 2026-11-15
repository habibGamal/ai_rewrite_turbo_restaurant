<?php

namespace App\Filament\Widgets;

use App\Core\Orders\GuestStats;
use App\Services\ChannelPerformanceReportService;
use Filament\Support\Enums\IconPosition;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ChannelPerformanceStatsWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    use InteractsWithPageFilters;

    protected ChannelPerformanceReportService $channelReportService;

    public function boot(): void
    {
        $this->channelReportService = app(ChannelPerformanceReportService::class);
    }

    protected function getStats(): array
    {
        $metrics = $this->getChannelMetrics();

        return [...[
            Stat::make('إجمالي الإيرادات', number_format($metrics['total_revenue'], 2).' ج.م')
                ->description('إجمالي إيرادات جميع القنوات')
                ->descriptionIcon('heroicon-m-banknotes', IconPosition::Before)
                ->color('success'),

            Stat::make('المرتجعات', number_format((float) $metrics['channel_summary']->sum('total_refunds'), 2).' ج.م')
                ->description('صافي المبيعات '.number_format((float) $metrics['channel_summary']->sum('net_sales'), 2).' ج.م')
                ->descriptionIcon('heroicon-m-arrow-uturn-left', IconPosition::Before)
                ->color('danger'),

            Stat::make('إجمالي الطلبات', number_format($metrics['total_orders']))
                ->description('إجمالي عدد الطلبات من جميع القنوات')
                ->descriptionIcon('heroicon-m-shopping-bag', IconPosition::Before)
                ->color('primary'),

            Stat::make('إجمالي العملاء', number_format($metrics['total_customers']))
                ->description('إجمالي العملاء الفريدين')
                ->descriptionIcon('heroicon-m-users', IconPosition::Before)
                ->color('info'),

            Stat::make('متوسط قيمة الطلب', number_format($metrics['avg_order_value'], 2).' ج.م')
                ->description('متوسط قيمة الطلب عبر جميع القنوات')
                ->descriptionIcon('heroicon-m-calculator', IconPosition::Before)
                ->color('warning'),

            Stat::make('أفضل قناة (إيرادات)',
                $metrics['top_channel_by_revenue'] ? $metrics['top_channel_by_revenue']->type_label : 'لا توجد')
                ->description($metrics['top_channel_by_revenue'] ?
                    number_format($metrics['top_channel_by_revenue']->total_sales, 2).' ج.م' : 'لا توجد مبيعات')
                ->descriptionIcon('heroicon-m-trophy', IconPosition::Before)
                ->color('success'),

            Stat::make('أفضل قناة (طلبات)',
                $metrics['top_channel_by_orders'] ? $metrics['top_channel_by_orders']->type_label : 'لا توجد')
                ->description($metrics['top_channel_by_orders'] ?
                    number_format($metrics['top_channel_by_orders']->total_orders).' طلب' : 'لا توجد طلبات')
                ->descriptionIcon('heroicon-m-chart-bar', IconPosition::Before)
                ->color('primary'),

            Stat::make('أكثر القنوات كفاءة',
                $metrics['most_efficient_channel'] ? $metrics['most_efficient_channel']->type_label : 'لا توجد')
                ->description($metrics['most_efficient_channel'] ?
                    number_format($metrics['most_efficient_channel']->efficiency_score, 1).'%' : 'لا توجد بيانات')
                ->descriptionIcon('heroicon-m-rocket-launch', IconPosition::Before)
                ->color('warning'),

            Stat::make('عدد القنوات النشطة', $metrics['channel_summary']->count())
                ->description('عدد قنوات البيع النشطة')
                ->descriptionIcon('heroicon-m-squares-plus', IconPosition::Before)
                ->color('gray'),
        ], ...$this->guestStats($metrics['guests'])];
    }

    /**
     * «عدد الأفراد» and «متوسط الفرد», shown only once some order carries a guest count.
     *
     * @return list<Stat>
     */
    private function guestStats(GuestStats $guests): array
    {
        if (! $guests->hasGuests()) {
            return [];
        }

        return [
            Stat::make('عدد الأفراد', number_format($guests->guests).' فرد')
                ->description('في '.number_format($guests->orders).' طلب مسجل به عدد الأفراد')
                ->descriptionIcon('heroicon-m-user-group', IconPosition::Before)
                ->color('info'),

            Stat::make('متوسط الفرد', number_format($guests->averagePerGuest(), 2).' ج.م')
                ->description('مبيعات الطلبات المسجل بها عدد الأفراد ÷ عدد الأفراد')
                ->descriptionIcon('heroicon-m-user', IconPosition::Before)
                ->color('success'),
        ];
    }

    protected function getColumns(): int
    {
        return 4;
    }

    private function getChannelMetrics(): array
    {
        $startDate = $this->pageFilters['startDate'] ?? now()->subDays(29)->startOfDay()->toDateString();
        $endDate = $this->pageFilters['endDate'] ?? now()->endOfDay()->toDateString();

        return $this->channelReportService->getChannelEfficiencyMetrics($startDate, $endDate);
    }
}
