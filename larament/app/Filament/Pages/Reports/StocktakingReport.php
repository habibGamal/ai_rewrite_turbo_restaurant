<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Widgets\StocktakingReportTable;

class StocktakingReport extends InventoryDocumentReport
{
    protected static string $routePath = 'stocktaking-report';

    protected static ?string $navigationLabel = 'تقرير الجرد';

    protected static ?string $title = 'تقرير الجرد';

    public function getWidgets(): array
    {
        return [StocktakingReportTable::class];
    }
}
