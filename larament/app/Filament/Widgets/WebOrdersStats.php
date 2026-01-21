<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class WebOrdersStats extends BaseWidget
{
    protected static bool $isLazy = false;
    protected ?string $pollingInterval = '30s';

    public function getHeading(): string
    {
        return 'حالة أوردرات الويب';
    }

    protected function getStats(): array
    {
        $statuses = [
            OrderStatus::PENDING,
            OrderStatus::PROCESSING,
            OrderStatus::OUT_FOR_DELIVERY
        ];

        $ordersQuery = Order::query()
            ->whereIn('type', [OrderType::WEB_TAKEAWAY , OrderType::WEB_DELIVERY])
            ->whereIn('status', $statuses);
        $pendingOrders = (clone $ordersQuery)
            ->where('status', OrderStatus::PENDING)
            ->selectRaw('count(*) as count, sum(total) as value, sum(profit) as profit')
            ->first();

        $processingOrders = (clone $ordersQuery)
            ->where('status', OrderStatus::PROCESSING)
            ->selectRaw('count(*) as count, sum(total) as value, sum(profit) as profit')
            ->first();

        $outForDeliveryOrders = (clone $ordersQuery)
            ->where('status', OrderStatus::OUT_FOR_DELIVERY)
            ->selectRaw('count(*) as count, sum(total) as value, sum(profit) as profit')
            ->first();

        return [
            Stat::make('في الإنتظار', ($pendingOrders->count ?? 0) . ' أوردر')
                ->description('بقيمة ' . number_format($pendingOrders->value ?? 0, 2) . ' جنيه - ربح ' . number_format($pendingOrders->profit ?? 0, 2) . ' جنيه')
                ->descriptionIcon('heroicon-m-clock')
                ->extraAttributes([
                    'class' => 'transition hover:scale-105 cursor-pointer',
                    'wire:click' => <<<JS
                        \$dispatch('filterUpdate',{filter:{status:'pending'}} )
                        document.getElementById('web_orders_table')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    JS
                ])
                ->color('gray'),

            Stat::make('تحت التشغيل', ($processingOrders->count ?? 0) . ' أوردر')
                ->description('بقيمة ' . number_format($processingOrders->value ?? 0, 2) . ' جنيه - ربح ' . number_format($processingOrders->profit ?? 0, 2) . ' جنيه')
                ->descriptionIcon('heroicon-m-cog-6-tooth')
                ->extraAttributes([
                    'class' => 'transition hover:scale-105 cursor-pointer',
                    'wire:click' => <<<JS
                        \$dispatch('filterUpdate',{filter:{status:'processing'}} )
                        document.getElementById('web_orders_table')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    JS
                ])
                ->color('warning'),

            Stat::make('في طريق التوصيل', ($outForDeliveryOrders->count ?? 0) . ' أوردر')
                ->description('بقيمة ' . number_format($outForDeliveryOrders->value ?? 0, 2) . ' جنيه - ربح ' . number_format($outForDeliveryOrders->profit ?? 0, 2) . ' جنيه')
                ->descriptionIcon('heroicon-m-truck')
                ->extraAttributes([
                    'class' => 'transition hover:scale-105 cursor-pointer',
                    'wire:click' => <<<JS
                        \$dispatch('filterUpdate',{filter:{status:'out_for_delivery'}} )
                        document.getElementById('web_orders_table')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    JS
                ])
                ->color('info'),
        ];
    }
}
