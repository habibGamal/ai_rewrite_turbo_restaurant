<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Widgets\TransfersReportTable;

class TransfersReport extends InventoryDocumentReport
{
    protected static string $routePath = 'transfers-report';

    protected static ?string $navigationLabel = 'تقرير التحويلات';

    protected static ?string $title = 'تقرير التحويلات';

    public function getWidgets(): array
    {
        return [TransfersReportTable::class];
    }
}
