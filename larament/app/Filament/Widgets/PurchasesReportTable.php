<?php

namespace App\Filament\Widgets;

use App\Filament\Exports\PurchasesReportExporter;
use App\Filament\Pages\Reports\PurchasesReport;
use App\Services\Reports\Inventory\PurchasesReportService;

class PurchasesReportTable extends InventoryDocumentReportTable
{
    protected static string $reportPage = PurchasesReport::class;

    protected static string $reportService = PurchasesReportService::class;

    protected static string $reportExporter = PurchasesReportExporter::class;
}
