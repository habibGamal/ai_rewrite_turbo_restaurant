<?php

namespace App\Filament\Widgets;

use App\Core\Orders\GuestStats;
use App\Services\DailyComparisonReportService;
use Filament\Support\Enums\IconPosition;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DailyComparisonStatsWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    use InteractsWithPageFilters;

    protected DailyComparisonReportService $reportService;

    public function boot(): void
    {
        $this->reportService = app(DailyComparisonReportService::class);
    }

    protected function getStats(): array
    {
        $startDate = $this->pageFilters['startDate'] ?? now()->startOfMonth()->toDateString();
        $endDate = $this->pageFilters['endDate'] ?? now()->endOfMonth()->toDateString();
        $viewType = $this->pageFilters['viewType'] ?? 'by_date';

        $summary = $this->reportService->getPeriodSummary($startDate, $endDate, $viewType);

        $bestSales = $summary['best_sales_period'];
        $bestOrders = $summary['best_orders_period'];

        return [...[
            Stat::make('إجمالي الطلبات', number_format($summary['total_orders']).' طلب')
                ->description('إجمالي الطلبات بالتاريخ الفعلي')
                ->descriptionIcon('heroicon-m-shopping-bag', IconPosition::Before)
                ->color('info'),

            Stat::make('إجمالي المبيعات', number_format($summary['total_sales'], 2).' ج.م')
                ->description('مجموع قيم الطلبات بالتاريخ الفعلي')
                ->descriptionIcon('heroicon-m-banknotes', IconPosition::Before)
                ->color('success'),

            Stat::make('المرتجعات', number_format($summary['total_refunds'], 2).' ج.م')
                ->description($summary['returns_count'].' مرتجع · صافي المبيعات '.number_format($summary['net_sales'], 2).' ج.م')
                ->descriptionIcon('heroicon-m-arrow-uturn-left', IconPosition::Before)
                ->color($summary['total_refunds'] > 0 ? 'danger' : 'gray'),

            Stat::make('إجمالي الأرباح', number_format($summary['total_profit'], 2).' ج.م')
                ->description('هامش الربح: '.number_format($summary['profit_margin'], 1).'% · بعد المرتجعات '.number_format($summary['net_profit'], 2))
                ->descriptionIcon('heroicon-m-chart-bar', IconPosition::Before)
                ->color('warning'),

            Stat::make('متوسط قيمة الطلب', number_format($summary['avg_order_value'], 2).' ج.م')
                ->description('متوسط المبيعات لكل طلب')
                ->descriptionIcon('heroicon-m-calculator', IconPosition::Before)
                ->color('primary'),

            Stat::make('أعلى مبيعات', $bestSales && $bestSales->total_sales > 0 ? $bestSales->day_label : 'لا توجد مبيعات')
                ->description($bestSales && $bestSales->total_sales > 0 ? number_format($bestSales->total_sales, 2).' ج.م' : '—')
                ->descriptionIcon('heroicon-m-trophy', IconPosition::Before)
                ->color('success'),

            Stat::make('أكثر طلبات', $bestOrders && $bestOrders->total_orders > 0 ? $bestOrders->day_label : 'لا توجد طلبات')
                ->description($bestOrders && $bestOrders->total_orders > 0 ? number_format($bestOrders->total_orders).' طلب' : '—')
                ->descriptionIcon('heroicon-m-star', IconPosition::Before)
                ->color('info'),

            Stat::make('متوسط المبيعات ', number_format($summary['avg_daily_sales'], 2).' ج.م')
                ->description('المبيعات مقسومة على عدد الفترات')
                ->descriptionIcon('heroicon-m-arrow-trending-up', IconPosition::Before)
                ->color('gray'),

            Stat::make('متوسط الطلبات ', number_format($summary['avg_daily_orders'], 1).' طلب')
                ->description('الطلبات مقسومة على عدد الفترات')
                ->descriptionIcon('heroicon-m-chart-pie', IconPosition::Before)
                ->color('gray'),
        ], ...$this->guestStats($summary['guests'])];
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
}
