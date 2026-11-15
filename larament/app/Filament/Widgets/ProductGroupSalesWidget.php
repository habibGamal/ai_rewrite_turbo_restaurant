<?php

namespace App\Filament\Widgets;

use App\Services\FeatureService;
use App\Services\ProductsSalesReportService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * Products sales report: totals per retail model (product group), every
 * size and colour of a model in one row. Shown while variants are on.
 */
class ProductGroupSalesWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = true;

    protected static ?string $pollingInterval = null;

    protected string $view = 'filament.widgets.product-group-sales';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return app(FeatureService::class)->variantsEnabled();
    }

    /**
     * @return array{groups: list<array<string, mixed>>}
     */
    protected function getViewData(): array
    {
        $filterType = $this->pageFilters['filterType'] ?? 'period';

        return [
            'groups' => app(ProductsSalesReportService::class)->getProductGroupSales(
                $this->pageFilters['startDate'] ?? now()->subDays(30)->startOfDay()->toDateString(),
                $this->pageFilters['endDate'] ?? now()->endOfDay()->toDateString(),
                $filterType === 'shifts' ? ($this->pageFilters['shifts'] ?? []) : null,
                ProductsSalesReportService::nullableFilterId($this->pageFilters['mainCategoryId'] ?? null),
                ProductsSalesReportService::nullableFilterId($this->pageFilters['subCategoryId'] ?? null),
            ),
        ];
    }
}
