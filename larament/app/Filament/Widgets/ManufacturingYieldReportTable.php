<?php

namespace App\Filament\Widgets;

use App\Filament\Exports\ManufacturingYieldReportExporter;
use App\Filament\Pages\Reports\ManufacturingYieldReport;
use App\Services\Reports\Inventory\ManufacturingYieldReportService;

class ManufacturingYieldReportTable extends InventoryDocumentReportTable
{
    protected static string $reportPage = ManufacturingYieldReport::class;

    protected static string $reportService = ManufacturingYieldReportService::class;

    protected static string $reportExporter = ManufacturingYieldReportExporter::class;
}
