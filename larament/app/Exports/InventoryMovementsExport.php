<?php

namespace App\Exports;

use App\Exports\InventoryMovements\InventoryItemsSheet;
use App\Exports\InventoryMovements\InventoryMovementTypesSheet;
use App\Services\Reports\Inventory\InventoryMovementsReportService;
use App\Services\Reports\Inventory\InventoryReportFilters;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * «تقرير حركة المخزون» as Excel: the movement types sheet and the items sheet.
 */
class InventoryMovementsExport implements WithMultipleSheets
{
    public function __construct(
        private readonly InventoryMovementsReportService $service,
        private readonly InventoryReportFilters $filters,
    ) {}

    public function sheets(): array
    {
        return [
            new InventoryMovementTypesSheet($this->service, $this->filters),
            new InventoryItemsSheet($this->service, $this->filters),
        ];
    }
}
