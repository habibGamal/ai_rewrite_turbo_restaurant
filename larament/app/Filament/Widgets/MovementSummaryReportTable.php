<?php

namespace App\Filament\Widgets;

use App\Filament\Exports\MovementSummaryReportExporter;
use App\Models\InventoryItemMovement;
use App\Services\Reports\Inventory\ItemLedgerReportService;
use App\Services\Reports\Inventory\MovementSummaryReportService;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Fluent;
use Illuminate\Support\HtmlString;

class MovementSummaryReportTable extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'ملخص حركة المخزون';

    public function table(Table $table): Table
    {
        $startDate = (string) ($this->pageFilters['startDate'] ?? $this->pageFilters['from'] ?? now()->subDays(29)->startOfDay()->toDateString());
        $endDate = (string) ($this->pageFilters['endDate'] ?? $this->pageFilters['to'] ?? now()->endOfDay()->toDateString());

        $warehouseId = ItemLedgerReportService::nullableId($this->pageFilters['warehouse_id'] ?? $this->pageFilters['warehouseId'] ?? null);
        $mainCategoryId = ItemLedgerReportService::nullableId($this->pageFilters['main_category_id'] ?? $this->pageFilters['mainCategoryId'] ?? null);
        $subCategoryId = ItemLedgerReportService::nullableId($this->pageFilters['sub_category_id'] ?? $this->pageFilters['subCategoryId'] ?? null);
        $productId = ItemLedgerReportService::nullableId($this->pageFilters['product_id'] ?? $this->pageFilters['productId'] ?? null);

        $summary = MovementSummaryReportService::rows(
            $startDate,
            $endDate,
            $warehouseId,
            $mainCategoryId,
            $subCategoryId,
            $productId,
        );

        $resolveRow = function (InventoryItemMovement $record) use ($summary): Fluent {
            $key = MovementSummaryReportService::reasonKeyById((int) $record->id) ?? 'purchase';

            return $summary->get($key) ?? new Fluent;
        };

        return $table
            ->query(MovementSummaryReportService::query())
            ->columns([
                TextColumn::make('reason_label')
                    ->label('نوع الحركة')
                    ->getStateUsing(fn (InventoryItemMovement $record): string => (string) ($resolveRow($record)->label ?? ''))
                    ->weight('medium')
                    ->color('primary'),

                TextColumn::make('operation')
                    ->label('الاتجاه')
                    ->badge()
                    ->getStateUsing(fn (InventoryItemMovement $record): string => ($resolveRow($record)->operation ?? '') === 'in' ? 'وارد' : 'صادر')
                    ->color(fn (string $state): string => $state === 'وارد' ? 'success' : 'danger')
                    ->alignCenter(),

                TextColumn::make('quantity')
                    ->label('إجمالي الكمية')
                    ->numeric(decimalPlaces: 3)
                    ->getStateUsing(fn (InventoryItemMovement $record): float => (float) ($resolveRow($record)->quantity ?? 0.0))
                    ->color(fn (float $state): string => $state > 0 ? 'info' : 'gray')
                    ->weight('bold')
                    ->alignCenter(),

                TextColumn::make('value')
                    ->label('إجمالي القيمة')
                    ->numeric(decimalPlaces: 2)
                    ->getStateUsing(fn (InventoryItemMovement $record): float => (float) ($resolveRow($record)->value ?? 0.0))
                    ->suffix(' جنيه')
                    ->weight('bold')
                    ->color(fn (float $state): string => $state > 0 ? 'primary' : 'gray')
                    ->alignCenter(),

                TextColumn::make('documents_count')
                    ->label('عدد المستندات')
                    ->numeric()
                    ->getStateUsing(fn (InventoryItemMovement $record): int => (int) ($resolveRow($record)->documents_count ?? 0))
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),
            ])
            ->recordActions([
                Action::make('drill_down')
                    ->label('عرض المستندات')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->color('primary')
                    ->modalHeading(fn (InventoryItemMovement $record): string => 'المستندات: '.($resolveRow($record)->label ?? ''))
                    ->modalWidth(Width::FourExtraLarge)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('إغلاق')
                    ->modalContent(fn (InventoryItemMovement $record): HtmlString => $this->renderDocumentsModal($resolveRow($record))),
            ])
            ->headerActions([
                ExportAction::make()
                    ->label('تصدير التقرير')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->exporter(MovementSummaryReportExporter::class)
                    ->options([
                        'from' => $startDate,
                        'to' => $endDate,
                        'warehouse_id' => $warehouseId,
                        'main_category_id' => $mainCategoryId,
                        'sub_category_id' => $subCategoryId,
                        'product_id' => $productId,
                    ])
                    ->fileName(fn (): string => 'movement-summary-report-'.now()->format('Y-m-d-H-i-s').'.xlsx'),
            ])
            ->paginated(false)
            ->striped();
    }

    /**
     * @param  Fluent<string, mixed>  $row
     */
    private function renderDocumentsModal(Fluent $row): HtmlString
    {
        $documents = $row->documents ?? collect();

        if ($documents->isEmpty()) {
            return new HtmlString('<div class="py-6 text-center text-sm text-gray-500">لا توجد مستندات مسجلة لهذا النوع في الفترة المحددة.</div>');
        }

        $rowsHtml = '';
        foreach ($documents as $doc) {
            $docType = e((string) ($doc->document_type ?? ''));
            $docNum = e((string) ($doc->document_number ?? '—'));
            $url = $doc->document_url ?? null;
            $docLink = $url !== null
                ? '<a href="'.e((string) $url).'" target="_blank" class="text-primary-600 underline font-semibold">#'.$docNum.'</a>'
                : '#'.$docNum;
            $date = e((string) ($doc->date ?? ''));
            $warehouse = e((string) ($doc->warehouse_name ?? '—'));
            $user = e((string) ($doc->user_name ?? '—'));
            $qty = number_format((float) ($doc->quantity ?? 0), 3);
            $val = number_format((float) ($doc->value ?? 0), 2);

            $rowsHtml .= "<tr>
                <td class=\"px-3 py-2 text-right\">{$docType}</td>
                <td class=\"px-3 py-2 text-center\">{$docLink}</td>
                <td class=\"px-3 py-2 text-center\">{$date}</td>
                <td class=\"px-3 py-2 text-center\">{$warehouse}</td>
                <td class=\"px-3 py-2 text-center\">{$user}</td>
                <td class=\"px-3 py-2 text-center font-medium\">{$qty}</td>
                <td class=\"px-3 py-2 text-center font-semibold\">{$val} جنيه</td>
            </tr>";
        }

        return new HtmlString('
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-right">نوع المستند</th>
                            <th class="px-3 py-2 text-center">رقم المستند</th>
                            <th class="px-3 py-2 text-center">التاريخ</th>
                            <th class="px-3 py-2 text-center">المخزن</th>
                            <th class="px-3 py-2 text-center">المستخدم</th>
                            <th class="px-3 py-2 text-center">الكمية</th>
                            <th class="px-3 py-2 text-center">القيمة</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">'.$rowsHtml.'</tbody>
                </table>
            </div>
        ');
    }
}
