<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Widgets\CostHistoryReportTable;

class CostHistoryReport extends InventoryDocumentReport
{
    protected static string $routePath = 'cost-history-report';

    protected static ?string $navigationLabel = 'سجل تغيير التكلفة';

    protected static ?string $title = 'سجل تغيير التكلفة';

    protected static ?int $navigationSort = 20;

    public function getWidgets(): array
    {
        return [CostHistoryReportTable::class];
    }
}
