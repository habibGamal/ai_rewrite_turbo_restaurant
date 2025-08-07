<?php

namespace App\Filament\Widgets;

use App\Services\ShiftsReportService;
use App\Enums\OrderType;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class PeriodShiftDoneOrdersStats extends BaseWidget
{
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
        $shifts = $this->getShifts();

        if ($shifts->isEmpty()) {
            return [];
        }

        $orderTypeStats = $this->shiftsReportService->calculatePeriodOrderTypeStats($shifts);

        $stats = [];

        // Map the new service format to the old format expected by widgets
        $statsMapping = [
            'dine_in' => ['key' => 'dineIn', 'label' => 'الاوردرات الصالة', 'icon' => 'heroicon-m-home', 'color' => 'success'],
            'delivery' => ['key' => 'delivery', 'label' => 'الاوردرات ديليفري', 'icon' => 'heroicon-m-truck', 'color' => 'info'],
            'takeaway' => ['key' => 'takeaway', 'label' => 'الاوردرات تيك اواي', 'icon' => 'heroicon-m-shopping-bag', 'color' => 'warning'],
            'talabat' => ['key' => 'talabat', 'label' => 'الاوردرات طلبات', 'icon' => 'heroicon-m-device-phone-mobile', 'color' => 'purple'],
            'web_delivery' => ['key' => 'webDelivery', 'label' => 'الاوردرات اونلاين ديليفري', 'icon' => 'heroicon-m-globe-alt', 'color' => 'danger'],
            'web_takeaway' => ['key' => 'webTakeaway', 'label' => 'الاوردرات اونلاين تيك اواي', 'icon' => 'heroicon-m-computer-desktop', 'color' => 'info'],
            'companies' => ['key' => 'companies', 'label' => 'اوردرات الشركات', 'icon' => 'heroicon-m-building-office', 'color' => 'gray'],
        ];

        foreach ($statsMapping as $enumValue => $config) {
            if (isset($orderTypeStats[$enumValue]) && $orderTypeStats[$enumValue]['count'] > 0) {
                $data = $orderTypeStats[$enumValue];
                $stats[] = Stat::make($config['label'], $data['count'] . ' اوردر')
                    ->description('بقيمة ' . number_format($data['value'], 2) . ' جنيه - ربح ' . number_format($data['profit'], 2) . ' جنيه')
                    ->descriptionIcon($config['icon'])
                    ->extraAttributes([
                        'class' => 'transition hover:scale-105 cursor-pointer',
                        'wire:click' => <<<JS
                            \$dispatch('filterUpdate',{filter:{type:'{$enumValue}'}} )
                            document.getElementById('orders_table')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        JS
                    ])
                    ->color($config['color']);
            }
        }

        return $stats;
    }

    private function getShifts()
    {
        $startDate = $this->filters['startDate'] ?? now()->subDays(7)->startOfDay()->toDateString();
        $endDate = $this->filters['endDate'] ?? now()->endOfDay()->toDateString();

        return $this->shiftsReportService->getShiftsInPeriod($startDate, $endDate);
    }
}
