<?php

namespace App\Filament\Widgets;

use App\Models\Shift;
use App\Services\ShiftsReportService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CurrentShiftDoneOrdersStats extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected ShiftsReportService $shiftsReportService;

    public function boot(): void
    {
        $this->shiftsReportService = app(ShiftsReportService::class);
    }

    public function getHeading(): string
    {
        return 'الاوردرات المكتملة';
    }

    protected function getStats(): array
    {
        $currentShift = $this->getCurrentShift();

        if (! $currentShift) {
            return [];
        }

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
        }, $this->shiftsReportService->calculateOrderTypeStats($currentShift));
    }

    private function getCurrentShift(): ?Shift
    {
        return $this->shiftsReportService->getCurrentShift();
    }
}
