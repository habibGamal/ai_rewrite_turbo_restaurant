<?php

namespace App\Modules\Manufacturing\Filament\Widgets;

use App\Filament\Widgets\InventoryDocumentReportTable;

use App\Modules\Manufacturing\Filament\Exports\ManufacturingYieldReportExporter;
use App\Modules\Manufacturing\Filament\Pages\Reports\ManufacturingYieldReport;
use App\Modules\Manufacturing\Services\ManufacturingYieldReportService;

class ManufacturingYieldReportTable extends InventoryDocumentReportTable
{
    protected static string $reportPage = ManufacturingYieldReport::class;

    protected static string $reportService = ManufacturingYieldReportService::class;

    protected static string $reportExporter = ManufacturingYieldReportExporter::class;
}
