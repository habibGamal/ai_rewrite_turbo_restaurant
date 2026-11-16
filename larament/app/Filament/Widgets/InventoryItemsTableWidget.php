<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Reports\ItemLedgerReport;
use App\Models\Product;
use App\Services\Reports\Inventory\InventoryMovementsReportService;
use App\Services\Reports\Inventory\InventoryReportFilters;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

/**
 * The items tab: per product, opening → in → out → waste → stocktaking
 * difference → closing, with the loss rate and how many days the closing
 * stock lasts. A row opens «كشف حركة صنف» for that product and period.
 */
class InventoryItemsTableWidget extends TableWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $filters = InventoryReportFilters::fromArray($this->pageFilters);
        $units = Product::getUnitOptions();
        $quantity = fn (TextColumn $column) => $column->numeric(decimalPlaces: 3)->sortable()->alignCenter();

        return $table
            ->heading('الأصناف')
            ->description('الكميات بوحدة كل صنف. الوارد والصادر من غير الهالك والجرد'.($filters->warehouseId === null ? ' والتحويلات بين المخازن' : '').'. اضغط على الصنف يفتح كشف حركته.')
            ->query(app(InventoryMovementsReportService::class)->itemsQuery($filters))
            ->columns([
                TextColumn::make('name')
                    ->label('الصنف')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Product $record): string => trim(($record->getAttribute('category_name') ?? '').' · '.($units[$record->unit] ?? $record->unit), ' ·')),
                $quantity(TextColumn::make('opening_qty')->label('أول المدة')),
                $quantity(TextColumn::make('in_qty')->label('وارد'))->color('success'),
                $quantity(TextColumn::make('out_qty')->label('صادر'))->color('warning'),
                $quantity(TextColumn::make('waste_qty')->label('هالك')),
                $quantity(TextColumn::make('count_qty')->label('فرق جرد'))
                    ->color(fn ($state): ?string => (float) $state < 0 ? 'danger' : null),
                $quantity(TextColumn::make('closing_qty')->label('آخر المدة'))->weight('bold'),
                TextColumn::make('closing_value')->label('القيمة (ج.م)')->numeric(decimalPlaces: 2)->sortable()->alignCenter(),
                TextColumn::make('loss_rate')
                    ->label('الفاقد %')
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 1).'%')
                    ->color(fn ($state): string => match (true) {
                        (float) $state >= 7 => 'danger',
                        (float) $state >= 3 => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('cover_days')
                    ->label('يكفي')
                    ->sortable()
                    ->alignCenter()
                    ->placeholder('مفيش سحب')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state).' يوم')
                    ->color(fn ($state): ?string => $state !== null && (float) $state < 3 ? 'danger' : null),
            ])
            ->defaultSort('loss_rate', 'desc')
            ->recordUrl(fn (Product $record): string => ItemLedgerReport::getUrl(['filters' => $filters->toLedgerFilters($record->id)]))
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->striped()
            ->emptyStateHeading('مفيش أصناف ليها رصيد أو حركة في الفترة دي');
    }
}
