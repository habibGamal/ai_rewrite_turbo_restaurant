<?php

namespace App\Filament\Widgets;

use App\Filament\Exports\TransfersReportExporter;
use App\Filament\Pages\Reports\TransfersReport;
use App\Services\Reports\Inventory\TransfersReportService;

class TransfersReportTable extends InventoryDocumentReportTable
{
    protected static string $reportPage = TransfersReport::class;

    protected static string $reportService = TransfersReportService::class;

    protected static string $reportExporter = TransfersReportExporter::class;
}
