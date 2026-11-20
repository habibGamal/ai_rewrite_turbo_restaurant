<?php

namespace App\Filament\Widgets;

use App\Services\Reports\Inventory\InventoryInsightsReportService;
use App\Services\Reports\Inventory\InventoryReportFilters;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * The warehouses tab: value and flows per warehouse, transfers between
 * warehouses, and stock issues by destination.
 */
class InventoryWarehousesWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected string $view = 'filament.widgets.inventory-warehouses';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $service = app(InventoryInsightsReportService::class);
        $filters = InventoryReportFilters::fromArray($this->pageFilters);

        return [
            'warehouses' => $service->warehouses($filters),
            'transfers' => $service->transferMatrix($filters),
            'issues' => $service->issueDestinations($filters),
        ];
    }
}
