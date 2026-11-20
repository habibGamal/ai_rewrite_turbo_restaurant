<?php

namespace App\Filament\Widgets;

use App\Services\Reports\Inventory\InventoryMovementsReportService;
use App\Services\Reports\Inventory\InventoryReportFilters;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Livewire\Attributes\On;

/**
 * One row per kind of movement in the period (value, documents, items,
 * share of its direction, change against the comparison period). Picking a
 * row, or a step of the value bridge, lists the documents behind it.
 */
class InventoryMovementTypesWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected string $view = 'filament.widgets.inventory-movement-types';

    protected int|string|array $columnSpan = 'full';

    public ?string $selected = null;

    #[On('inventory-movement-type-selected')]
    public function select(?string $key = null): void
    {
        $this->selected = ($key === null || $key === $this->selected) ? null : $key;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $service = app(InventoryMovementsReportService::class);
        $filters = InventoryReportFilters::fromArray($this->pageFilters);
        $rows = $service->typeRows($filters);

        $buckets = $this->selected ? InventoryMovementsReportService::bucketsOf($this->selected) : [];
        $selectedLabel = null;
        if ($this->selected !== null) {
            $labels = array_column(array_filter($rows, fn (array $row) => in_array($row['key'], $buckets, true)), 'label');
            $selectedLabel = $labels === [] ? null : implode(' / ', $labels);
        }

        return [
            'rows' => $rows,
            'comparing' => $filters->comparison() !== null,
            'comparisonWord' => $filters->comparisonWord(),
            'selectedBuckets' => $buckets,
            'selectedLabel' => $selectedLabel,
            'documents' => $buckets === [] ? [] : $service->documents($filters, $buckets),
        ];
    }
}
