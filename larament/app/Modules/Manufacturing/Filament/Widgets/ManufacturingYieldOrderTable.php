<?php

namespace App\Modules\Manufacturing\Filament\Widgets;

use App\Filament\Widgets\InventoryDocumentReportTable;

use App\Modules\Manufacturing\Filament\Exports\ManufacturingYieldOrderExporter;
use App\Modules\Manufacturing\Filament\Pages\Reports\ManufacturingYieldReport;
use App\Models\ManufacturingOrderOutput;
use App\Modules\Manufacturing\Services\ManufacturingYieldReportService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ManufacturingYieldOrderTable extends InventoryDocumentReportTable
{
    protected static string $reportPage = ManufacturingYieldReport::class;

    protected static string $reportService = ManufacturingYieldReportService::class;

    protected static string $reportExporter = ManufacturingYieldOrderExporter::class;

    protected static string $queryMethod = 'detailsQuery';

    protected static ?string $heading = 'تفاصيل أوامر التصنيع والمدخلات والمخرجات';

    public function table(Table $table): Table
    {
        $table = parent::table($table);
        $columns = $table->getColumns();
        /** @var TextColumn $inputs */
        $inputs = $columns['inputs'];
        $inputs->sortable(false)
            ->state(fn (ManufacturingOrderOutput $record) => ManufacturingYieldOrderExporter::inputDescription($record))->wrap();

        return $table->columns($columns);
    }
}
