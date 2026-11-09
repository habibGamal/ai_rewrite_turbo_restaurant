<?php

namespace App\Filament\Widgets;

use App\Services\ProductsSalesReportService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

class ProductsSalesGroupedWidget extends Widget
{
    protected static bool $isLazy = false;

    protected static ?string $pollingInterval = null;

    use InteractsWithPageFilters;

    protected string $view = 'filament.widgets.products-sales-grouped';

    protected int|string|array $columnSpan = 'full';

    protected ProductsSalesReportService $productsReportService;

    public function boot(): void
    {
        $this->productsReportService = app(ProductsSalesReportService::class);
    }

    /**
     * @return array{groups: list<array<string, mixed>>}
     */
    protected function getViewData(): array
    {
        $filterType = $this->pageFilters['filterType'] ?? 'period';
        $startDate = $this->pageFilters['startDate'] ?? now()->subDays(30)->startOfDay()->toDateString();
        $endDate = $this->pageFilters['endDate'] ?? now()->endOfDay()->toDateString();
        $shiftIds = $filterType === 'shifts' ? ($this->pageFilters['shifts'] ?? []) : null;

        return [
            'groups' => $this->productsReportService->groupedProductSales(
                $startDate,
                $endDate,
                $shiftIds,
                ProductsSalesReportService::nullableFilterId($this->pageFilters['mainCategoryId'] ?? null),
                ProductsSalesReportService::nullableFilterId($this->pageFilters['subCategoryId'] ?? null),
            ),
        ];
    }
}
