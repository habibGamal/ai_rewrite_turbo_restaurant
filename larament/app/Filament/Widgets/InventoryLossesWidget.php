<?php

namespace App\Filament\Widgets;

use App\Services\Reports\Inventory\InventoryInsightsReportService;
use App\Services\Reports\Inventory\InventoryReportFilters;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * The losses tab: loss per week, the items that lost the most (against the
 * recipe consumption for restaurants), items short in more than one count,
 * and slow-moving stock for retail.
 */
class InventoryLossesWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected string $view = 'filament.widgets.inventory-losses';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $service = app(InventoryInsightsReportService::class);
        $filters = InventoryReportFilters::fromArray($this->pageFilters);

        return [
            'trend' => $service->lossTrend($filters),
            'items' => $service->topLossItems($filters),
            'reasons' => $service->wasteReasons($filters),
            'repeated' => $service->repeatedShortages($filters),
            'restaurant' => $service->restaurant(),
            'slowMoving' => $service->retail() ? $service->slowMoving($filters) : null,
            'slowDays' => InventoryInsightsReportService::SLOW_MOVING_DAYS,
            'ledgerFilters' => fn (int $productId): array => $filters->toLedgerFilters($productId),
        ];
    }
}
