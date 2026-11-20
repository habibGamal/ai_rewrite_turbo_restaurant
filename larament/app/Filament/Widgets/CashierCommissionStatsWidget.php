<?php

namespace App\Filament\Widgets;

use App\Services\CashierCommissionReportService;
use App\Services\ShiftsReportService;
use Carbon\Carbon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CashierCommissionStatsWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    use InteractsWithPageFilters;

    protected ShiftsReportService $shiftsReportService;

    protected CashierCommissionReportService $commissionService;

    public function boot(): void
    {
        $this->shiftsReportService = app(ShiftsReportService::class);
        $this->commissionService = app(CashierCommissionReportService::class);
    }

    public function getHeading(): string
    {
        return 'ملخص عمولات وأداء الكاشير';
    }

    protected function getStats(): array
    {
        $filterType = $this->pageFilters['filterType'] ?? 'period';
        $shiftIds = [];
        $startDate = null;
        $endDate = null;

        if ($filterType === 'shifts') {
            $shiftIds = $this->pageFilters['shifts'] ?? [];
            if (empty($shiftIds)) {
                return $this->emptyStats();
            }
        } else {
            $startDate = Carbon::parse($this->pageFilters['startDate'] ?? now()->subDays(6)->startOfDay()->toDateString())->startOfDay();
            $endDate = Carbon::parse($this->pageFilters['endDate'] ?? now()->endOfDay()->toDateString())->endOfDay();

            $shiftIds = $this->shiftsReportService->getShiftsInPeriodQuery($startDate->toDateString(), $endDate->toDateString(), null)
                ->pluck('id')
                ->toArray();

            if (empty($shiftIds)) {
                return $this->emptyStats();
            }
        }

        $stats = $this->commissionService->getSummaryStats($shiftIds, $startDate, $endDate);

        return [
            Stat::make('الكاشيرات النشطين', $stats['total_cashiers'])
                ->description('الكاشيرات الذين أنشأوا طلبات أو عدلوا أصناف')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('primary'),

            Stat::make('إجمالي الطلبات المنشأة', number_format($stats['total_orders']).' طلب')
                ->description('بقيمة: '.number_format($stats['total_orders_value'], 2).' ج.م')
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->color('success'),

            Stat::make('طلبات بدون خصم', number_format($stats['orders_without_discount_count']).' طلب')
                ->description('بقيمة: '.number_format($stats['orders_without_discount_value'], 2).' ج.م')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),

            Stat::make('طلبات بخصم', number_format($stats['orders_with_discount_count']).' طلب')
                ->description('بقيمة: '.number_format($stats['orders_with_discount_value'], 2).' ج.م (خصم: '.number_format($stats['total_discount_amount'], 2).' ج.م)')
                ->descriptionIcon('heroicon-m-tag')
                ->color('warning'),

            Stat::make('الأصناف المضافة', number_format($stats['total_items_added']).' صنف')
                ->description('بقيمة: +'.number_format($stats['total_items_added_value'], 2).' ج.م')
                ->descriptionIcon('heroicon-m-plus-circle')
                ->color('info'),

            Stat::make('الأصناف المحذوفة', number_format($stats['total_items_removed']).' صنف')
                ->description('بقيمة: -'.number_format($stats['total_items_removed_value'], 2).' ج.م')
                ->descriptionIcon('heroicon-m-minus-circle')
                ->color('danger'),
        ];
    }

    private function emptyStats(): array
    {
        return [
            Stat::make('الكاشيرات النشطين', 0)->color('gray'),
            Stat::make('إجمالي الطلبات المنشأة', '0 طلب')->color('gray'),
            Stat::make('طلبات بدون خصم', '0 طلب')->color('gray'),
            Stat::make('طلبات بخصم', '0 طلب')->color('gray'),
            Stat::make('الأصناف المضافة', '0 صنف')->color('gray'),
            Stat::make('الأصناف المحذوفة', '0 صنف')->color('gray'),
        ];
    }
}
