<?php

namespace App\Filament\Widgets;

use App\Enums\InventoryMovementOperation;
use App\Filament\Exports\ItemLedgerReportExporter;
use App\Models\InventoryItemMovement;
use App\Services\Reports\Inventory\ItemLedgerReportService;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

class ItemLedgerReportTable extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'كشف حركة صنف';

    public function table(Table $table): Table
    {
        $startDate = (string) ($this->pageFilters['startDate'] ?? $this->pageFilters['from'] ?? now()->subDays(29)->startOfDay()->toDateString());
        $endDate = (string) ($this->pageFilters['endDate'] ?? $this->pageFilters['to'] ?? now()->endOfDay()->toDateString());

        $warehouseId = ItemLedgerReportService::nullableId($this->pageFilters['warehouse_id'] ?? $this->pageFilters['warehouseId'] ?? null);
        $mainCategoryId = ItemLedgerReportService::nullableId($this->pageFilters['main_category_id'] ?? $this->pageFilters['mainCategoryId'] ?? null);
        $subCategoryId = ItemLedgerReportService::nullableId($this->pageFilters['sub_category_id'] ?? $this->pageFilters['subCategoryId'] ?? null);
        $productId = ItemLedgerReportService::nullableId($this->pageFilters['product_id'] ?? $this->pageFilters['productId'] ?? null);

        return $table
            ->query(ItemLedgerReportService::query($productId, $warehouseId, $startDate, $endDate, $mainCategoryId, $subCategoryId))
            ->columns([
                TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                TextColumn::make('product.name')
                    ->label('الصنف')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('document_type')
                    ->label('نوع المستند')
                    ->badge()
                    ->getStateUsing(fn (InventoryItemMovement $record): string => ItemLedgerReportService::documentTypeLabel($record->referenceable_type, $record->reason)),

                TextColumn::make('document_number')
                    ->label('رقم المستند')
                    ->getStateUsing(fn (InventoryItemMovement $record): string => $record->referenceable_id !== null ? '#'.$record->referenceable_id : '—')
                    ->url(fn (InventoryItemMovement $record): ?string => ItemLedgerReportService::documentUrl($record->referenceable_type, $record->referenceable_id))
                    ->openUrlInNewTab()
                    ->color(fn (InventoryItemMovement $record): string => ItemLedgerReportService::documentUrl($record->referenceable_type, $record->referenceable_id) !== null ? 'primary' : 'gray')
                    ->alignCenter(),

                TextColumn::make('in_quantity')
                    ->label('وارد')
                    ->numeric(decimalPlaces: 3)
                    ->getStateUsing(fn (InventoryItemMovement $record): float => $record->operation === InventoryMovementOperation::IN ? round((float) $record->quantity, 3) : 0.0)
                    ->color(fn (float $state): string => $state > 0 ? 'success' : 'gray')
                    ->alignCenter(),

                TextColumn::make('out_quantity')
                    ->label('صادر')
                    ->numeric(decimalPlaces: 3)
                    ->getStateUsing(fn (InventoryItemMovement $record): float => $record->operation === InventoryMovementOperation::OUT ? round((float) $record->quantity, 3) : 0.0)
                    ->color(fn (float $state): string => $state > 0 ? 'danger' : 'gray')
                    ->alignCenter(),

                TextColumn::make('running_balance')
                    ->label('الرصيد التراكمي')
                    ->numeric(decimalPlaces: 3)
                    ->getStateUsing(fn (InventoryItemMovement $record): float => ItemLedgerReportService::runningBalanceForMovement($record, $warehouseId))
                    ->weight('bold')
                    ->color('primary')
                    ->alignCenter(),

                TextColumn::make('unit_cost')
                    ->label('تكلفة الوحدة')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' جنيه')
                    ->alignCenter(),

                TextColumn::make('total_cost')
                    ->label('القيمة')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' جنيه')
                    ->weight('medium')
                    ->alignCenter(),

                TextColumn::make('user.name')
                    ->label('المستخدم')
                    ->placeholder('—')
                    ->alignCenter(),

                TextColumn::make('warehouse.name')
                    ->label('المخزن')
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),
            ])
            ->recordActions([
                Action::make('view_document')
                    ->label('عرض المستند')
                    ->icon('heroicon-o-eye')
                    ->visible(fn (InventoryItemMovement $record): bool => ItemLedgerReportService::documentUrl($record->referenceable_type, $record->referenceable_id) !== null)
                    ->url(fn (InventoryItemMovement $record): ?string => ItemLedgerReportService::documentUrl($record->referenceable_type, $record->referenceable_id))
                    ->openUrlInNewTab()
                    ->color('primary'),
            ])
            ->headerActions([
                ExportAction::make()
                    ->label('تصدير التقرير')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->exporter(ItemLedgerReportExporter::class)
                    ->fileName(fn (): string => 'item-ledger-report-'.now()->format('Y-m-d-H-i-s').'.xlsx'),
            ])
            ->striped()
            ->paginated([10, 25, 50, 100])
            ->emptyStateHeading('لا توجد حركات')
            ->emptyStateDescription('لم يتم العثور على أي حركات مخزون للفلاتر المحددة.')
            ->emptyStateIcon('heroicon-o-arrows-right-left');
    }
}
