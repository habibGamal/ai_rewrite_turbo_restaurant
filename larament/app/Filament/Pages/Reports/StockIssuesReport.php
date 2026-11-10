<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Widgets\StockIssuesReportTable;

class StockIssuesReport extends InventoryDocumentReport
{
    protected static string $routePath = 'stock-issues-report';

    protected static ?string $navigationLabel = 'تقرير أذون الصرف';

    protected static ?string $title = 'تقرير أذون الصرف';

    public function getWidgets(): array
    {
        return [StockIssuesReportTable::class];
    }
}
