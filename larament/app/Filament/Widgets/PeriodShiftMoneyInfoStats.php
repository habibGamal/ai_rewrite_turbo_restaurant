<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\CreditReconciliationStats;
use App\Filament\Widgets\Concerns\GuestAverageStats;
use App\Filament\Widgets\Concerns\OtherRevenuesStats;
use App\Filament\Widgets\Concerns\ReturnsStats;
use App\Filament\Widgets\Concerns\ScopesReportToTerminal;
use App\Services\FeatureService;
use App\Services\Reports\EquipmentReportService;
use App\Services\ShiftsReportService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PeriodShiftMoneyInfoStats extends BaseWidget
{
    use ScopesReportToTerminal;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    use CreditReconciliationStats;
    use GuestAverageStats;
    use InteractsWithPageFilters;
    use OtherRevenuesStats;
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
        $this->reportTerminal();

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

            ...(app(FeatureService::class)->customFeesEnabled() || abs((float) ($stats['fees'] ?? 0)) > 0.0001 || (float) ($stats['feesRefunds'] ?? 0) > 0
                ? [Stat::make('الرسوم الإضافية', number_format((float) ($stats['fees'] ?? 0), 2).' جنيه')
                    ->description(app(FeatureService::class)->returnsEnabled() && (float) ($stats['feesRefunds'] ?? 0) > 0
                        ? 'مرتجع '.number_format((float) $stats['feesRefunds'], 2).' · صافي '.number_format((float) ($stats['fees'] ?? 0) - (float) $stats['feesRefunds'], 2).' جنيه'
                        : 'رسوم إضافية ضمن المبيعات المكتملة')
                    ->descriptionIcon('heroicon-m-plus-circle')
                    ->color('success')]
                : []),

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

            ...$this->guestAverageStats($this->shiftsReportService->shiftIdsQueryFromFilters($this->pageFilters)),

            ...$this->otherRevenuesStats($stats),
            ...app(EquipmentReportService::class)->shiftStats($this->shiftsReportService->shiftIdsQueryFromFilters($this->pageFilters)),
            ...(app(FeatureService::class)->outsourcedOrdersEnabled() || abs((float) ($stats['externalCash'] ?? 0)) > 0.0001
                ? [Stat::make('نقد الطلبات الخارجية', number_format($stats['externalCash'], 2).' جنيه')]
                : []),
            ...(app(FeatureService::class)->outsourcedOrdersEnabled() || abs((float) ($stats['settlements'] ?? 0)) > 0.0001
                ? [Stat::make('مدفوعات البائعين الخارجيين', number_format($stats['settlements'], 2).' جنيه')]
                : []),
            Stat::make('النقدية المتاحة', number_format($stats['availableCash'], 2).' جنيه'),
            $this->netProfitStat($stats),
        ];
    }

    /**
     * Net profit = order profit after returns + other revenues − expenses.
     *
     * @param  array<string, mixed>  $stats
     */
    private function netProfitStat(array $stats): Stat
    {
        $revenues = (float) ($stats['revenues'] ?? 0);
        $net = (float) $stats['netProfit'] + $revenues - (float) $stats['expenses'];

        return Stat::make('صافي الربح', number_format($net, 2).' جنيه')
            ->description(app(FeatureService::class)->returnsEnabled()
                ? (abs($revenues) > 0.0001 ? 'الربح بعد المرتجعات + الإيرادات الأخرى − المصروفات' : 'الربح بعد المرتجعات والمصروفات')
                : (abs($revenues) > 0.0001 ? 'الربح + الإيرادات الأخرى − المصروفات' : 'الربح بعد المصروفات'))
            ->descriptionIcon('heroicon-m-currency-dollar')
            ->color($net >= 0 ? 'success' : 'danger');
    }

    private function getPeriodStats()
    {
        return $this->shiftsReportService->calculatePeriodStats(
            ...$this->shiftsReportService->scopeFromFilters($this->pageFilters)
        );
    }
}
