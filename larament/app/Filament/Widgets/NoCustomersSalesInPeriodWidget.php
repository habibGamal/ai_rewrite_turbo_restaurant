<?php

namespace App\Filament\Widgets;

use App\Services\CustomersPerformanceReportService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

class NoCustomersSalesInPeriodWidget extends Widget
{
    use InteractsWithPageFilters;

    protected string $view = 'filament.widgets.no-customers-sales-in-period';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected CustomersPerformanceReportService $customersReportService;

    public function boot(): void
    {
        $this->customersReportService = app(CustomersPerformanceReportService::class);
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
        [$defaultStart, $defaultEnd] = CustomersPerformanceReportService::defaultPeriod();
        $startDate = $this->pageFilters['startDate'] ?? $defaultStart;
        $endDate = $this->pageFilters['endDate'] ?? $defaultEnd;

        return $this->customersReportService->getPeriodInfo($startDate, $endDate);
    }
}
