<?php

namespace App\Filament\Widgets;

use App\Filament\Exports\StocktakingReportExporter;
use App\Filament\Pages\Reports\StocktakingReport;
use App\Services\Reports\Inventory\StocktakingReportService;

class StocktakingReportTable extends InventoryDocumentReportTable
{
    protected static string $reportPage = StocktakingReport::class;

    protected static string $reportService = StocktakingReportService::class;

    protected static string $reportExporter = StocktakingReportExporter::class;
}
