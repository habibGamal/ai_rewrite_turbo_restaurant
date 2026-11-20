<?php

namespace App\Filament\Widgets;

use App\Filament\Exports\CostHistoryReportExporter;
use App\Filament\Pages\Reports\CostHistoryReport;
use App\Services\Reports\Inventory\CostHistoryReportService;

class CostHistoryReportTable extends InventoryDocumentReportTable
{
    protected static string $reportPage = CostHistoryReport::class;

    protected static string $reportService = CostHistoryReportService::class;

    protected static string $reportExporter = CostHistoryReportExporter::class;
}
