<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Services\CashierCommissionReportService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CashierDetailStatsWidget extends BaseWidget
{
    public ?User $record = null;

    protected function getStats(): array
    {
        if (! $this->record) {
            return [];
        }

        $service = app(CashierCommissionReportService::class);
        $stats = $service->getCashierStats($this->record->id);

        return [
            Stat::make('إجمالي الطلبات المنشأة', number_format($stats['orders_count']).' طلب')
                ->description('بقيمة إجمالية: '.number_format($stats['orders_total_value'], 2).' ج.م')
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->color('primary'),

            Stat::make('طلبات بدون خصم', number_format($stats['orders_without_discount_count']).' طلب')
                ->description('بقيمة: '.number_format($stats['orders_without_discount_value'], 2).' ج.م')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),

            Stat::make('طلبات بخصم', number_format($stats['orders_with_discount_count']).' طلب')
                ->description('بقيمة: '.number_format($stats['orders_with_discount_value'], 2).' ج.م (خصم: '.number_format($stats['total_discount_amount'], 2).' ج.م)')
                ->descriptionIcon('heroicon-m-tag')
                ->color('warning'),

            Stat::make('الأصناف المضافة', number_format($stats['items_added_count']).' صنف')
                ->description('بقيمة: +'.number_format($stats['items_added_value'], 2).' ج.م')
                ->descriptionIcon('heroicon-m-plus-circle')
                ->color('info'),

            Stat::make('الأصناف المحذوفة', number_format($stats['items_removed_count']).' صنف')
                ->description('بقيمة: -'.number_format($stats['items_removed_value'], 2).' ج.م')
                ->descriptionIcon('heroicon-m-minus-circle')
                ->color('danger'),

            Stat::make('صافي قيمة التعديلات', number_format($stats['net_items_value'], 2).' ج.م')
                ->description($stats['net_items_value'] >= 0 ? 'صافي إيجابي' : 'صافي سلبي')
                ->descriptionIcon('heroicon-m-calculator')
                ->color($stats['net_items_value'] >= 0 ? 'success' : 'danger'),
        ];
    }
}
