<?php

namespace App\Filament\Widgets;

use App\Costing\CostingMethod;
use App\Filament\Exports\InventoryDocumentReportExporter;
use App\Filament\Pages\Reports\InventoryDocumentReport;
use App\Services\Reports\StockReportService;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

abstract class InventoryDocumentReportTable extends TableWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    /** @var class-string<InventoryDocumentReport> */
    protected static string $reportPage;

    protected static string $reportService;

    protected static string $queryMethod = 'query';

    /** @var class-string<InventoryDocumentReportExporter> */
    protected static string $reportExporter;

    public static function canView(): bool
    {
        return static::$reportPage::canAccess();
    }

    public function boot(): void
    {
        abort_unless(static::canView(), 403);
    }

    public function table(Table $table): Table
    {
        $dates = StockReportService::resolveDates($this->pageFilters['startDate'] ?? null, $this->pageFilters['endDate'] ?? null);
        $columns = [];
        foreach (static::$reportExporter::columnDefinitions() as $name => [$label, $type]) {
            $column = TextColumn::make($name)->label($label)->sortable();
            if ($type === 'text') {
                $column->searchable();
            }
            if (in_array($type, ['quantity', 'money', 'cost', 'percent'], true)) {
                $column->numeric(decimalPlaces: match ($type) {
                    'quantity' => 3, 'cost' => 4, default => 2,
                });
            }
            if ($type === 'percent') {
                $column->suffix('%')->placeholder('—');
            }
            if ($type === 'method') {
                $column->formatStateUsing(fn (?CostingMethod $state) => $state?->label());
            }
            if ($type === 'date') {
                $column->date();
            }
            if ($type === 'datetime') {
                $column->dateTime();
            }
            $columns[] = $column;
        }

        return $table->heading(static::$heading ?? static::$reportPage::getNavigationLabel())
            ->query(static::$reportService::{static::$queryMethod}($dates['start'], $dates['end'], $this->pageFilters ?? []))
            ->columns($columns)->defaultSort('id')->striped()->paginated([10, 25, 50, 100])
            ->emptyStateHeading('لا توجد بيانات للفترة والفلاتر المحددة')
            ->headerActions([
                ExportAction::make()->label('تصدير Excel')->icon('heroicon-o-arrow-down-tray')->color('success')
                    ->exporter(static::$reportExporter)->formats([ExportFormat::Xlsx]),
            ])
            ->recordAction(null)->recordUrl(null);
    }
}
