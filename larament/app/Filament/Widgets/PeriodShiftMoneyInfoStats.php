<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\CreditReconciliationStats;
use App\Filament\Widgets\Concerns\ReturnsStats;
use App\Services\FeatureService;
use App\Services\ShiftsReportService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PeriodShiftMoneyInfoStats extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    use CreditReconciliationStats;
    use InteractsWithPageFilters;
    use ReturnsStats;

    protected ShiftsReportService $shiftsReportService;

    public function boot(): void
    {
        $this->shiftsReportService = app(ShiftsReportService::class);
    }

    public function getHeading(): string
    {
        return 'الإيراد الإجمالي';
    }

    protected function getStats(): array
    {
        $stats = $this->getPeriodStats();

        return [
            Stat::make('المبيعات', number_format($stats['sales'], 2).' جنيه')
                ->description('إجمالي المبيعات المكتملة')
                ->descriptionIcon('heroicon-m-receipt-percent')
                ->color('success'),

            Stat::make('الارباح', number_format($stats['profit'], 2).' جنيه')
                ->description(number_format($stats['profitPercent'], 2).'% من المبيعات')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('success'),

            ...$this->returnsStats($stats),

            Stat::make('المصروفات', number_format($stats['expenses'], 2).' جنيه')
                ->description('إجمالي مصروفات الفترة')
                ->descriptionIcon('heroicon-m-arrow-down-circle')
                ->extraAttributes([
                    'class' => 'transition hover:scale-105 cursor-pointer',
                    'x-on:click' => "document.getElementById('expenses_table')?.scrollIntoView({ behavior: 'smooth', block: 'start' })",
                ])
                ->color('danger'),

            Stat::make('الخصومات', number_format($stats['discounts'], 2).' جنيه')
                ->description('إجمالي الخصومات المطبقة')
                ->descriptionIcon('heroicon-m-percent-badge')
                ->extraAttributes([
                    'class' => 'transition hover:scale-105 cursor-pointer',
                    'wire:click' => <<<'JS'
                        $dispatch('filterUpdate',{filter:{has_discount:'1'}} )
                        document.getElementById('orders_table')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    JS
                ])
                ->color('warning'),

            Stat::make('النقود المدفوعة كاش', number_format($stats['cashPayments'], 2).' جنيه')
                ->description('المدفوعات النقدية')
                ->descriptionIcon('heroicon-m-banknotes')
                ->extraAttributes([
                    'class' => 'transition hover:scale-105 cursor-pointer',
                    'wire:click' => <<<'JS'
                        $dispatch('filterUpdate',{filter:{payment_method:'cash'}} )
                        document.getElementById('orders_table')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    JS
                ])
                ->color('primary'),

            Stat::make('النقود المدفوعة فيزا', number_format($stats['cardPayments'], 2).' جنيه')
                ->description('المدفوعات بالكارت')
                ->descriptionIcon('heroicon-m-credit-card')
                ->extraAttributes([
                    'class' => 'transition hover:scale-105 cursor-pointer',
                    'wire:click' => <<<'JS'
                        $dispatch('filterUpdate',{filter:{payment_method:'card'}} )
                        document.getElementById('orders_table')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    JS
                ])
                ->color('info'),

            Stat::make('النقود المدفوعة فيزا طلبات', number_format($stats['talabatCardPayments'], 2).' جنيه')
                ->description('المدفوعات بكارت طلبات')
                ->descriptionIcon('heroicon-m-device-phone-mobile')
                ->extraAttributes([
                    'class' => 'transition hover:scale-105 cursor-pointer',
                    'wire:click' => <<<'JS'
                        $dispatch('filterUpdate',{filter:{payment_method:'talabat_card'}} )
                        document.getElementById('orders_table')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    JS
                ])
                ->color('warning'),

            ...$this->creditReconciliationStats($stats, 'الفترة'),

            Stat::make('متوسط قيمة الاوردر', number_format($stats['avgReceiptValue'], 2).' جنيه')
                ->description('متوسط قيمة الطلب الواحد')
                ->descriptionIcon('heroicon-m-calculator')
                ->color('primary'),

            ...(app(FeatureService::class)->outsourcedOrdersEnabled() || abs((float) ($stats['externalCash'] ?? 0)) > 0.0001
                ? [Stat::make('نقد الطلبات الخارجية', number_format($stats['externalCash'], 2).' جنيه')]
                : []),
            ...(app(FeatureService::class)->outsourcedOrdersEnabled() || abs((float) ($stats['settlements'] ?? 0)) > 0.0001
                ? [Stat::make('مدفوعات البائعين الخارجيين', number_format($stats['settlements'], 2).' جنيه')]
                : []),
            Stat::make('النقدية المتاحة', number_format($stats['availableCash'], 2).' جنيه'),
            Stat::make('صافي الربح', number_format($stats['netProfit'] - $stats['expenses'], 2).' جنيه')
                ->description('الربح بعد المرتجعات والمصروفات')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color($stats['netProfit'] - $stats['expenses'] >= 0 ? 'success' : 'danger'),
        ];
    }

    private function getPeriodStats()
    {
        return $this->shiftsReportService->calculatePeriodStats(
            ...$this->shiftsReportService->scopeFromFilters($this->pageFilters)
        );
    }
}
