<?php

namespace App\Filament\Widgets;

use App\Services\Reports\Inventory\InventoryMovementsReportService;
use App\Services\Reports\Inventory\InventoryReportFilters;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * How the stock value moved from the start of the period to its end: a
 * waterfall with one step per kind of movement. Clicking a step lists its
 * documents in the movement types widget.
 */
class InventoryValueBridgeWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected string $view = 'filament.widgets.inventory-value-bridge';

    protected int|string|array $columnSpan = 'full';

    public function select(string $key): void
    {
        $this->dispatch('inventory-movement-type-selected', key: $key);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $filters = InventoryReportFilters::fromArray($this->pageFilters);
        $steps = app(InventoryMovementsReportService::class)->bridge($filters);

        $max = 0.0;
        $min = 0.0;
        foreach ($steps as $step) {
            $max = max($max, $step['from'], $step['to']);
            $min = min($min, $step['from'], $step['to']);
        }
        $range = ($max - $min) ?: 1.0;

        $bars = array_map(function (array $step) use ($max, $range): array {
            $high = max($step['from'], $step['to']);
            $low = min($step['from'], $step['to']);

            return [
                ...$step,
                'top' => round(($max - $high) / $range * 100, 2),
                'height' => max(round(($high - $low) / $range * 100, 2), 0.6),
                'delta' => $step['kind'] === 'total' ? $step['to'] : $step['to'] - $step['from'],
            ];
        }, $steps);

        return [
            'bars' => $bars,
            'singleWarehouse' => $filters->warehouseId !== null,
        ];
    }
}
