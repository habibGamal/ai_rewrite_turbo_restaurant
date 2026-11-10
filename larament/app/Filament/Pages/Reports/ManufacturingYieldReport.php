<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Widgets\ManufacturingYieldOrderTable;
use App\Filament\Widgets\ManufacturingYieldReportTable;

class ManufacturingYieldReport extends InventoryDocumentReport
{
    protected static string $routePath = 'manufacturing-yield-report';

    protected static ?string $navigationLabel = 'تقرير التصنيع والهالك الإنتاجي';

    protected static ?string $title = 'تقرير التصنيع والهالك الإنتاجي';

    public function getWidgets(): array
    {
        return [ManufacturingYieldReportTable::class, ManufacturingYieldOrderTable::class];
    }
}
