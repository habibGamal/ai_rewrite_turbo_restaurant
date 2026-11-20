<?php

namespace App\Filament\Widgets\Concerns;

use App\Services\FeatureService;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Pay-later cards that make the report add up:
 * المبيعات − آجل غير محصل + تحصيلات آجل سابقة = إجمالي المحصل (كاش + فيزا + فيزا طلبات).
 */
trait CreditReconciliationStats
{
    /**
     * @param  array<string, mixed>  $stats  output of ShiftsReportService::calculateShiftStats / calculatePeriodStats
     * @return list<Stat>
     */
    protected function creditReconciliationStats(array $stats, string $scopeLabel): array
    {
        $uncollected = (float) ($stats['uncollectedCredit'] ?? 0);
        $previous = (float) ($stats['previousCollections'] ?? 0);
        $received = (float) $stats['cashPayments'] + (float) $stats['cardPayments'] + (float) $stats['talabatCardPayments'];

        if (! app(FeatureService::class)->deferredPaymentEnabled()) {
            return [
                Stat::make('إجمالي المحصل', number_format($received, 2).' جنيه')
                    ->description('كاش + فيزا + فيزا طلبات')
                    ->descriptionIcon('heroicon-m-scale')
                    ->extraAttributes([
                        'class' => 'transition hover:scale-105 cursor-pointer',
                        'x-on:click' => "document.getElementById('orders_table')?.scrollIntoView({ behavior: 'smooth', block: 'start' })",
                    ])
                    ->color('success'),
            ];
        }

        return [
            Stat::make('آجل غير محصل', number_format($uncollected, 2).' جنيه')
                ->description(($stats['uncollectedCreditCount'] ?? 0).' طلب بيع في '.$scopeLabel.' ولم يُحصّل بالكامل فيه')
                ->descriptionIcon('heroicon-m-clock')
                ->extraAttributes([
                    'class' => 'transition hover:scale-105 cursor-pointer',
                    'wire:click' => <<<'JS'
                        $dispatch('filterUpdate',{filter:{has_credit:'1'}} )
                        document.getElementById('orders_table')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    JS
                ])
                ->color($uncollected > 0 ? 'danger' : 'gray'),

            Stat::make('تحصيلات آجل سابقة', number_format($previous, 2).' جنيه')
                ->description(($stats['previousCollectionsCount'] ?? 0).' طلب من شفتات أخرى تم تحصيله في '.$scopeLabel)
                ->descriptionIcon('heroicon-m-arrow-uturn-left')
                // Scroll only, no Livewire call: wire:click runs its value as `$wire.<value>`, so
                // plain JS there breaks. Alpine's x-on:click runs it as-is.
                ->extraAttributes([
                    'class' => 'transition hover:scale-105 cursor-pointer',
                    'x-on:click' => "document.getElementById('credit_collections_table')?.scrollIntoView({ behavior: 'smooth', block: 'start' })",
                ])
                ->color($previous > 0 ? 'info' : 'gray'),

            Stat::make('إجمالي المحصل', number_format($received, 2).' جنيه')
                ->description('المبيعات '.number_format((float) $stats['sales'], 2)
                    .' − الآجل '.number_format($uncollected, 2)
                    .' + التحصيلات '.number_format($previous, 2))
                ->descriptionIcon('heroicon-m-scale')
                ->extraAttributes([
                    'class' => 'transition hover:scale-105 cursor-pointer',
                    'x-on:click' => "document.getElementById('orders_table')?.scrollIntoView({ behavior: 'smooth', block: 'start' })",
                ])
                ->color('success'),
        ];
    }
}
