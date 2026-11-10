<?php

namespace App\Filament\Widgets;

use App\Filament\Exports\WasteReportExporter;
use App\Filament\Pages\Reports\WasteReport;
use App\Services\Reports\Inventory\WasteReportService;

class WasteReportTable extends InventoryDocumentReportTable
{
    protected static string $reportPage = WasteReport::class;

    protected static string $reportService = WasteReportService::class;

    protected static string $reportExporter = WasteReportExporter::class;
}
