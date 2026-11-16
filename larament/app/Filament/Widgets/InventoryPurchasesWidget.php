<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Reports\CostHistoryReport;
use App\Services\Reports\Inventory\InventoryInsightsReportService;
use App\Services\Reports\Inventory\InventoryReportFilters;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * The purchases and cost tab: purchases per supplier and the products whose
 * cost changed, with what the change cost in the period's purchases.
 */
class InventoryPurchasesWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected string $view = 'filament.widgets.inventory-purchases';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $service = app(InventoryInsightsReportService::class);
        $filters = InventoryReportFilters::fromArray($this->pageFilters);

        return [
            'suppliers' => $service->suppliers($filters),
            'costChanges' => $service->costChanges($filters),
            'costHistoryUrl' => CostHistoryReport::getUrl(['filters' => $filters->toLedgerFilters()]),
        ];
    }
}
