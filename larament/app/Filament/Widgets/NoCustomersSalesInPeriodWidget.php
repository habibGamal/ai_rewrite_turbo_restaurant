<?php

namespace App\Filament\Widgets;

use App\Services\CustomersPerformanceReportService;
use Filament\Widgets\Widget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class NoCustomersSalesInPeriodWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static string $view = 'filament.widgets.no-customers-sales-in-period';

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
        $startDate = $this->filters['startDate'] ?? now()->subDays(30)->startOfDay()->toDateString();
        $endDate = $this->filters['endDate'] ?? now()->endOfDay()->toDateString();

        return $this->customersReportService->getPeriodInfo($startDate, $endDate);
    }
}
