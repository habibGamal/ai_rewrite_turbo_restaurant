<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Services\CashierCommissionReportService;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CashierDetailStatsWidget extends BaseWidget
{
    public ?User $record = null;

    public array $shiftIds = [];

    public ?string $startDate = null;

    public ?string $endDate = null;

    public string $filterType = 'period';

    public bool $hasFilter = false;

    public function mount(
        ?User $record = null,
        array $shiftIds = [],
        ?string $startDate = null,
        ?string $endDate = null,
        string $filterType = 'period',
        bool $hasFilter = false
    ): void {
        $this->record = $record;
        $this->shiftIds = $shiftIds;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->filterType = $filterType;
        $this->hasFilter = $hasFilter;
    }

    protected function getStats(): array
    {
        if (! $this->record) {
            return [];
        }

        $service = app(CashierCommissionReportService::class);

        if ($this->hasFilter) {
            if (empty($this->shiftIds)) {
                return $this->emptyStats();
            }

            $startDate = $this->startDate ? Carbon::parse($this->startDate) : null;
            $endDate = $this->endDate ? Carbon::parse($this->endDate) : null;

            $stats = $service->getCashierStats($this->record->id, $this->shiftIds, $startDate, $endDate);
        } else {
            $stats = $service->getCashierStats($this->record->id);
        }

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

    private function emptyStats(): array
    {
        return [
            Stat::make('إجمالي الطلبات المنشأة', '0 طلب')
                ->description('بقيمة إجمالية: 0.00 ج.م')
                ->color('gray'),
            Stat::make('طلبات بدون خصم', '0 طلب')
                ->description('بقيمة: 0.00 ج.م')
                ->color('gray'),
            Stat::make('طلبات بخصم', '0 طلب')
                ->description('بقيمة: 0.00 ج.م (خصم: 0.00 ج.م)')
                ->color('gray'),
            Stat::make('الأصناف المضافة', '0 صنف')
                ->description('بقيمة: +0.00 ج.م')
                ->color('gray'),
            Stat::make('الأصناف المحذوفة', '0 صنف')
                ->description('بقيمة: -0.00 ج.م')
                ->color('gray'),
            Stat::make('صافي قيمة التعديلات', '0.00 ج.م')
                ->description('صافي إيجابي')
                ->color('gray'),
        ];
    }
}
