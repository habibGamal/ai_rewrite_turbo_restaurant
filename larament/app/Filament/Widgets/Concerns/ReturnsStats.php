<?php

namespace App\Filament\Widgets\Concerns;

use App\Filament\Widgets\Returns\ShiftReturnsTable;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Gross / returns / net cards. Refunds count in the shift that paid them,
 * and cash refunds are already taken out of «النقدية المتاحة».
 */
trait ReturnsStats
{
    /**
     * @param  array<string, mixed>  $stats  output of ShiftsReportService::calculateShiftStats / calculatePeriodStats
     * @return list<Stat>
     */
    protected function returnsStats(array $stats): array
    {
        $amount = (float) ($stats['returnsAmount'] ?? 0);
        $count = (int) ($stats['returnsCount'] ?? 0);

        $methods = array_filter([
            'كاش' => (float) ($stats['cashRefunds'] ?? 0),
            'فيزا' => (float) ($stats['cardRefunds'] ?? 0),
            'فيزا طلبات' => (float) ($stats['talabatCardRefunds'] ?? 0),
        ], fn (float $value): bool => abs($value) > 0.0001);
        $split = implode(' · ', array_map(
            fn (string $label, float $value): string => $label.' '.number_format($value, 2),
            array_keys($methods),
            $methods,
        ));

        $damaged = (float) ($stats['damagedQuantity'] ?? 0);

        return [
            Stat::make('المرتجعات', number_format($amount, 2).' جنيه')
                ->description($count.' مرتجع'.($split !== '' ? ' · '.$split : '').($damaged > 0 ? ' · تالف '.rtrim(rtrim(number_format($damaged, 2), '0'), '.') : ''))
                ->descriptionIcon('heroicon-m-arrow-uturn-left')
                ->color($amount > 0 ? 'danger' : 'gray')
                // Opens the returns table further down the report.
                ->extraAttributes([
                    'class' => 'transition hover:scale-105 cursor-pointer',
                    'title' => 'عرض جدول المرتجعات',
                    'x-on:click' => "document.getElementById('".ShiftReturnsTable::ANCHOR."')?.scrollIntoView({ behavior: 'smooth', block: 'start' })",
                ]),

            Stat::make('صافي المبيعات', number_format((float) ($stats['netSales'] ?? 0), 2).' جنيه')
                ->description('المبيعات − المرتجعات')
                ->descriptionIcon('heroicon-m-scale')
                ->color('success'),

            Stat::make('الأرباح بعد المرتجعات', number_format((float) ($stats['netProfit'] ?? 0), 2).' جنيه')
                ->description('الأرباح − أثر المرتجعات (التالف يُخصم بالكامل)')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('success'),
        ];
    }
}
