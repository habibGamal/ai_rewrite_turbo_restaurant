<?php

namespace App\Filament\Widgets;

use App\Services\Reports\Inventory\InventoryInsightsReportService;
use App\Services\Reports\Inventory\InventoryReportFilters;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * The alerts tab: checks on the period's stock data (negative balances,
 * zero-cost movements, manual adjustments, large count variances, waste
 * concentrated on one user).
 */
class InventoryAlertsWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected string $view = 'filament.widgets.inventory-alerts';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $service = app(InventoryInsightsReportService::class);
        $filters = InventoryReportFilters::fromArray($this->pageFilters);

        return [
            'alerts' => $service->alerts($filters),
        ];
    }
}
