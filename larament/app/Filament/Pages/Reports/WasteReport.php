<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Widgets\WasteReportTable;

class WasteReport extends InventoryDocumentReport
{
    protected static string $routePath = 'waste-report';

    protected static ?string $navigationLabel = 'تقرير الهالك';

    protected static ?string $title = 'تقرير الهالك';

    public function getWidgets(): array
    {
        return [WasteReportTable::class];
    }
}
