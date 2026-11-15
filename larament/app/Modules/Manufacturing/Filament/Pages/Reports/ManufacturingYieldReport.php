<?php

namespace App\Modules\Manufacturing\Filament\Pages\Reports;

use App\Filament\Pages\Reports\InventoryDocumentReport;

use App\Modules\Manufacturing\Filament\Widgets\ManufacturingYieldOrderTable;
use App\Modules\Manufacturing\Filament\Widgets\ManufacturingYieldReportTable;

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
