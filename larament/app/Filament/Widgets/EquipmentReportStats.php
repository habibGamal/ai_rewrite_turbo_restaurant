<?php

namespace App\Filament\Widgets;

use App\Services\Reports\EquipmentReportService;
use App\Services\ShiftsReportService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class EquipmentReportStats extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $scope = app(ShiftsReportService::class)->shiftIdsQueryFromFilters($this->pageFilters);
        $summary = app(EquipmentReportService::class)->summary($scope, $this->pageFilters['order_type'] ?? null);

        return [
            Stat::make('عدد مرات الاستخدام', number_format($summary['uses']))
                ->descriptionIcon('heroicon-m-wrench-screwdriver')
                ->color('primary'),
            Stat::make('إيراد المعدات', number_format($summary['revenue'], 2).' جنيه')
                ->color('success'),
            Stat::make('مرتجع المعدات', number_format($summary['refunded'], 2).' جنيه')
                ->description($summary['refunded_uses'].' استخدام مرتجع · الصافي '.number_format($summary['net_revenue'], 2).' جنيه')
                ->color('warning'),
            Stat::make('تكلفة المعدات', number_format($summary['cost'], 2).' جنيه')
                ->color('danger'),
            Stat::make('ربح المعدات', number_format($summary['net_profit'], 2).' جنيه')
                ->description('بعد المرتجعات')
                ->color($summary['net_profit'] >= 0 ? 'success' : 'danger'),
        ];
    }
}
