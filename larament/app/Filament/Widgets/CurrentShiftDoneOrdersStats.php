<?php

namespace App\Filament\Widgets;

use App\Core\Orders\GuestStats;
use App\Filament\Widgets\Concerns\ScopesReportToTerminal;
use App\Models\Shift;
use App\Services\ShiftsReportService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CurrentShiftDoneOrdersStats extends BaseWidget
{
    use InteractsWithPageFilters;
    use ScopesReportToTerminal;

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
        $this->reportTerminal();

        $currentShift = $this->getCurrentShift();

        if (! $currentShift) {
            return [];
        }

        $types = $this->shiftsReportService->calculateOrderTypeStats($currentShift);

        $stats = array_map(function (array $type): Stat {
            $average = $type['count'] > 0 ? $type['value'] / $type['count'] : 0;
            $code = json_encode($type['code'], JSON_HEX_APOS | JSON_HEX_QUOT);

            $description = 'بقيمة '.number_format($type['value'], 2).' جنيه - ربح '.number_format($type['profit'], 2).' جنيه - متوسط '.number_format($average, 2).' جنيه';
            if (($type['guests_summary'] ?? null) !== null) {
                $description .= ' - '.$type['guests_summary'];
            }

            return Stat::make($type['label'], $type['count'].' طلب')
                ->description($description)
                ->extraAttributes([
                    'class' => 'transition hover:scale-105 cursor-pointer',
                    'wire:click' => "\$dispatch('filterUpdate', {filter: {type: {$code}}}); document.getElementById('orders_table')?.scrollIntoView({ behavior: 'smooth', block: 'start' });",
                ])
                ->color($type['color']);
        }, $types);

        $guests = new GuestStats(
            (int) array_sum(array_column($types, 'guests')),
            (float) array_sum(array_column($types, 'guest_sales')),
        );
        if ($guests->hasGuests()) {
            $stats[] = Stat::make('عدد الأفراد', number_format($guests->guests).' فرد')
                ->description('متوسط الفرد '.number_format($guests->averagePerGuest(), 2).' جنيه')
                ->color('info');
        }

        return $stats;
    }

    private function getCurrentShift(): ?Shift
    {
        return $this->shiftsReportService->getCurrentShift();
    }
}
