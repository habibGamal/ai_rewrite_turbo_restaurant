<?php

namespace App\Exports\InventoryMovements;

use App\Services\Reports\Inventory\InventoryMovementsReportService;
use App\Services\Reports\Inventory\InventoryReportFilters;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class InventoryMovementTypesSheet implements FromArray, ShouldAutoSize, WithEvents, WithHeadings, WithTitle
{
    use RightToLeftSheet;

    public function __construct(
        private readonly InventoryMovementsReportService $service,
        private readonly InventoryReportFilters $filters,
    ) {}

    public function title(): string
    {
        return 'أنواع الحركة';
    }

    public function headings(): array
    {
        $headings = ['نوع الحركة', 'الاتجاه', 'المستندات', 'الأصناف', 'القيمة', 'النسبة %'];
        if ($this->filters->comparison() !== null) {
            $headings[] = 'التغير عن '.$this->filters->comparisonWord().' %';
        }

        return $headings;
    }

    public function array(): array
    {
        $comparing = $this->filters->comparison() !== null;

        return array_map(function (array $row) use ($comparing): array {
            $line = [$row['label'], $row['direction'] === 'in' ? 'دخول' : 'خروج', $row['documents'], $row['items'], $row['value'], $row['share']];
            if ($comparing) {
                $line[] = $row['change'];
            }

            return $line;
        }, $this->service->typeRows($this->filters));
    }
}
