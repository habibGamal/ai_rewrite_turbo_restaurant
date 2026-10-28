<?php

namespace App\Filament\Widgets;

use App\Services\PeakHoursPerformanceReportService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

class NoPeakHoursSalesInPeriodWidget extends Widget
{
    protected static bool $isLazy = false;

    protected static ?string $pollingInterval = null;

    use InteractsWithPageFilters;

    protected string $view = 'filament.widgets.no-peak-hours-sales-in-period';

    protected int|string|array $columnSpan = 'full';

    protected PeakHoursPerformanceReportService $peakHoursReportService;

    public function boot(): void
    {
        $this->peakHoursReportService = app(PeakHoursPerformanceReportService::class);
    }

    protected function getViewData(): array
    {
        $periodInfo = $this->getPeriodInfo();

        return [
            'title' => $periodInfo['title'],
            'description' => $periodInfo['description'],
        ];
    }

    private function getPeriodInfo(): array
    {
        $startDate = $this->pageFilters['startDate'] ?? now()->subDays(29)->startOfDay()->toDateString();
        $endDate = $this->pageFilters['endDate'] ?? now()->endOfDay()->toDateString();

        return $this->peakHoursReportService->getPeriodInfo($startDate, $endDate);
    }
}
