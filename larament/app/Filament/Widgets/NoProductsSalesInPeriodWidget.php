<?php

namespace App\Filament\Widgets;

use App\Services\ProductsSalesReportService;
use Filament\Widgets\Widget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class NoProductsSalesInPeriodWidget extends Widget
{
    protected static bool $isLazy = false;
    protected static ?string $pollingInterval = null;

    use InteractsWithPageFilters;

    protected static string $view = 'filament.widgets.no-products-sales-in-period';

    protected int | string | array $columnSpan = 'full';

    protected ProductsSalesReportService $productsReportService;

    public function boot(): void
    {
        $this->productsReportService = app(ProductsSalesReportService::class);
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

        return $this->productsReportService->getPeriodInfo($startDate, $endDate);
    }
}
