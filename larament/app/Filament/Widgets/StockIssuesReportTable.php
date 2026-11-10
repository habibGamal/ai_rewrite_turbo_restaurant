<?php

namespace App\Filament\Widgets;

use App\Filament\Exports\StockIssuesReportExporter;
use App\Filament\Pages\Reports\StockIssuesReport;
use App\Services\Reports\Inventory\StockIssuesReportService;

class StockIssuesReportTable extends InventoryDocumentReportTable
{
    protected static string $reportPage = StockIssuesReport::class;

    protected static string $reportService = StockIssuesReportService::class;

    protected static string $reportExporter = StockIssuesReportExporter::class;
}
