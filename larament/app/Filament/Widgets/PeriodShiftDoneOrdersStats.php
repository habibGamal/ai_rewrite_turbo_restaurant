<?php

namespace App\Filament\Widgets;

use App\Services\ShiftsReportService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PeriodShiftDoneOrdersStats extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    use InteractsWithPageFilters;

    protected ShiftsReportService $shiftsReportService;

    public function boot(): void
    {
        $this->shiftsReportService = app(ShiftsReportService::class);
    }

    public function getHeading(): string
    {
        return 'الاوردرات المكتملة حسب النوع';
    }

    protected function getStats(): array
    {
        return array_map(function (array $type): Stat {
            $average = $type['count'] > 0 ? $type['value'] / $type['count'] : 0;
            $code = json_encode($type['code'], JSON_HEX_APOS | JSON_HEX_QUOT);

            return Stat::make($type['label'], $type['count'].' طلب')
                ->description('بقيمة '.number_format($type['value'], 2).' جنيه - ربح '.number_format($type['profit'], 2).' جنيه - متوسط '.number_format($average, 2).' جنيه')
                ->extraAttributes([
                    'class' => 'transition hover:scale-105 cursor-pointer',
                    'wire:click' => "\$dispatch('filterUpdate', {filter: {type: {$code}}}); document.getElementById('orders_table')?.scrollIntoView({ behavior: 'smooth', block: 'start' });",
                ])
                ->color($type['color']);
        }, $this->calculatePeriodOrderTypeStats());
    }

    private function calculatePeriodOrderTypeStats()
    {
        $filterType = $this->pageFilters['filterType'] ?? 'period';

        if ($filterType === 'shifts') {
            $shiftIds = $this->pageFilters['shifts'] ?? [];

            return $this->shiftsReportService->calculatePeriodOrderTypeStats(null, null, $shiftIds);
        } else {
            $startDate = $this->pageFilters['startDate'] ?? now()->subDays(value: 7)->startOfDay()->toDateString();
            $endDate = $this->pageFilters['endDate'] ?? now()->endOfDay()->toDateString();

            return $this->shiftsReportService->calculatePeriodOrderTypeStats($startDate, $endDate, null);
        }
    }
}
