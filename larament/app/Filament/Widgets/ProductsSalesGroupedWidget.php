<?php

namespace App\Filament\Widgets;

use App\Services\ProductsSalesReportService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

class ProductsSalesGroupedWidget extends Widget
{
    protected static bool $isLazy = true;

    protected static ?string $pollingInterval = null;

    use InteractsWithPageFilters;

    protected string $view = 'filament.widgets.products-sales-grouped';

    protected int|string|array $columnSpan = 'full';

    protected ProductsSalesReportService $productsReportService;

    /**
     * @var list<int>
     */
    public array $expandedCategories = [];

    public function boot(): void
    {
        $this->productsReportService = app(ProductsSalesReportService::class);
    }

    public function toggleCategory(int $categoryId): void
    {
        if (in_array($categoryId, $this->expandedCategories, true)) {
            $this->expandedCategories = array_values(array_diff($this->expandedCategories, [$categoryId]));
        } else {
            $this->expandedCategories[] = $categoryId;
        }
    }

    public function expandAll(): void
    {
        $filterType = $this->pageFilters['filterType'] ?? 'period';
        $startDate = $this->pageFilters['startDate'] ?? now()->subDays(30)->startOfDay()->toDateString();
        $endDate = $this->pageFilters['endDate'] ?? now()->endOfDay()->toDateString();
        $shiftIds = $filterType === 'shifts' ? ($this->pageFilters['shifts'] ?? []) : null;
        $mainCategoryId = ProductsSalesReportService::nullableFilterId($this->pageFilters['mainCategoryId'] ?? null);
        $subCategoryId = ProductsSalesReportService::nullableFilterId($this->pageFilters['subCategoryId'] ?? null);

        $categories = $this->productsReportService->getMainCategoriesSalesSummary(
            $startDate,
            $endDate,
            $shiftIds,
            $mainCategoryId,
            $subCategoryId,
        );

        $this->expandedCategories = array_map(
            fn (array $cat): int => (int) $cat['main_category_id'],
            $categories
        );
    }

    public function collapseAll(): void
    {
        $this->expandedCategories = [];
    }

    /**
     * @return array{
     *     categories: list<array{
     *         main_category_id: int,
     *         main_category_name: string,
     *         total_quantity: float,
     *         total_sales: float,
     *         total_profit: float,
     *         products_count: int
     *     }>,
     *     details: array<int, array<string, mixed>>,
     *     expandedCategories: list<int>
     * }
     */
    protected function getViewData(): array
    {
        $filterType = $this->pageFilters['filterType'] ?? 'period';
        $startDate = $this->pageFilters['startDate'] ?? now()->subDays(30)->startOfDay()->toDateString();
        $endDate = $this->pageFilters['endDate'] ?? now()->endOfDay()->toDateString();
        $shiftIds = $filterType === 'shifts' ? ($this->pageFilters['shifts'] ?? []) : null;
        $mainCategoryId = ProductsSalesReportService::nullableFilterId($this->pageFilters['mainCategoryId'] ?? null);
        $subCategoryId = ProductsSalesReportService::nullableFilterId($this->pageFilters['subCategoryId'] ?? null);

        $categories = $this->productsReportService->getMainCategoriesSalesSummary(
            $startDate,
            $endDate,
            $shiftIds,
            $mainCategoryId,
            $subCategoryId,
        );

        // If user specifically filtered by one category in the page filter, auto-expand it
        if ($mainCategoryId !== null && empty($this->expandedCategories)) {
            $this->expandedCategories = [$mainCategoryId];
        }

        $details = [];
        foreach ($this->expandedCategories as $catId) {
            $groups = $this->productsReportService->groupedProductSales(
                $startDate,
                $endDate,
                $shiftIds,
                $catId,
                $subCategoryId,
            );

            if (! empty($groups)) {
                $details[$catId] = $groups[0];
            }
        }

        return [
            'categories' => $categories,
            'details' => $details,
            'expandedCategories' => $this->expandedCategories,
        ];
    }
}
