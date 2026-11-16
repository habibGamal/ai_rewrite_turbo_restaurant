<?php

namespace App\Exports\InventoryMovements;

use App\Models\Product;
use App\Services\Reports\Inventory\InventoryMovementsReportService;
use App\Services\Reports\Inventory\InventoryReportFilters;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class InventoryItemsSheet implements FromArray, ShouldAutoSize, WithEvents, WithHeadings, WithTitle
{
    use RightToLeftSheet;

    public function __construct(
        private readonly InventoryMovementsReportService $service,
        private readonly InventoryReportFilters $filters,
    ) {}

    public function title(): string
    {
        return 'الأصناف';
    }

    public function headings(): array
    {
        return ['الصنف', 'التصنيف', 'الوحدة', 'أول المدة', 'قيمة أول المدة', 'وارد', 'صادر', 'هالك', 'فرق جرد', 'آخر المدة', 'قيمة آخر المدة', 'الفاقد %', 'يكفي (يوم)'];
    }

    public function array(): array
    {
        $units = Product::getUnitOptions();

        return $this->service->itemsQuery($this->filters)
            ->orderBy('name')
            ->get()
            ->map(fn (Product $item): array => [
                $item->name,
                $item->getAttribute('category_name'),
                $units[$item->unit] ?? $item->unit,
                round((float) $item->getAttribute('opening_qty'), 3),
                round((float) $item->getAttribute('opening_value'), 2),
                round((float) $item->getAttribute('in_qty'), 3),
                round((float) $item->getAttribute('out_qty'), 3),
                round((float) $item->getAttribute('waste_qty'), 3),
                round((float) $item->getAttribute('count_qty'), 3),
                round((float) $item->getAttribute('closing_qty'), 3),
                round((float) $item->getAttribute('closing_value'), 2),
                round((float) $item->getAttribute('loss_rate'), 1),
                $item->getAttribute('cover_days') === null ? null : round((float) $item->getAttribute('cover_days')),
            ])
            ->all();
    }
}
