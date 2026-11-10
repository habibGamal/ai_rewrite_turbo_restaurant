<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Widgets\PurchasesReportTable;

class PurchasesReport extends InventoryDocumentReport
{
    protected static string $routePath = 'purchases-report';

    protected static ?string $navigationLabel = 'تقرير المشتريات';

    protected static ?string $title = 'تقرير المشتريات';

    public function getWidgets(): array
    {
        return [PurchasesReportTable::class];
    }
}
