<?php

namespace App\Filament\Widgets;

use App\Filament\Exports\CategoryPerformanceExporter;
use App\Services\ProductsSalesReportService;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class CategoryPerformanceWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected static ?string $pollingInterval = null;

    protected static ?string $heading = 'أداء التصنيفات';

    public string $categoryMode = 'main';

    protected ProductsSalesReportService $productsReportService;

    public function boot(): void
    {
        $this->productsReportService = app(ProductsSalesReportService::class);
    }

    public function setCategoryMode(string $mode): void
    {
        $this->categoryMode = in_array($mode, ['main', 'secondary'], true) ? $mode : 'main';
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('أداء التصنيفات')
            ->description(fn (): string => $this->categoryMode === 'secondary'
                ? 'عرض وتحليل مبيعات وأرباح التصنيفات الفرعية'
                : 'عرض وتحليل مبيعات وأرباح التصنيفات الرئيسية')
            ->query(
                $this->getCategoryPerformance()
            )
            ->headerActions([
                Action::make('mode_main')
                    ->label('التصنيفات الرئيسية')
                    ->icon('heroicon-m-squares-2x2')
                    ->color(fn (): string => $this->categoryMode === 'main' ? 'primary' : 'gray')
                    ->outlined(fn (): bool => $this->categoryMode !== 'main')
                    ->action(fn () => $this->setCategoryMode('main')),

                Action::make('mode_secondary')
                    ->label('التصنيفات الفرعية')
                    ->icon('heroicon-m-rectangle-group')
                    ->color(fn (): string => $this->categoryMode === 'secondary' ? 'primary' : 'gray')
                    ->outlined(fn (): bool => $this->categoryMode !== 'secondary')
                    ->action(fn () => $this->setCategoryMode('secondary')),

                ExportAction::make()
                    ->label('تصدير تقرير أداء التصنيفات')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->exporter(CategoryPerformanceExporter::class)
                    ->fileName(fn () => 'category-performance-'.$this->categoryMode.'-'.now()->format('Y-m-d-H-i-s').'.xlsx'),
            ])
            ->columns([
                TextColumn::make('category_name')
                    ->label(fn (): string => $this->categoryMode === 'secondary' ? 'التصنيف الفرعي' : 'التصنيف الرئيسي')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('categories.name', 'like', "%{$search}%");
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('categories.name', $direction);
                    }),

                TextColumn::make('parent_category_name')
                    ->label('التصنيف الرئيسي')
                    ->visible(fn (): bool => $this->categoryMode === 'secondary')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('parent_categories.name', 'like', "%{$search}%");
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('parent_categories.name', $direction);
                    })
                    ->default('-'),

                TextColumn::make('products_count')
                    ->label('عدد المنتجات')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('total_quantity')
                    ->label('إجمالي الكمية')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('total_sales')
                    ->label('إجمالي المبيعات')
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('total_profit')
                    ->label('إجمالي الربح')
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('profit_margin')
                    ->label('هامش الربح %')
                    ->state(function ($record) {
                        return $record->total_sales > 0
                            ? number_format(($record->total_profit / $record->total_sales) * 100, 1).'%'
                            : '0%';
                    }),

                TextColumn::make('avg_sales_per_product')
                    ->label('متوسط المبيعات')
                    ->getStateUsing(function ($record) {
                        $avg = $record->total_quantity > 0 ? $record->total_sales / $record->total_quantity : 0;

                        return number_format($avg, 2).' ج.م';
                    }),

                TextColumn::make('avg_profit_per_product')
                    ->label('متوسط الربح')
                    ->getStateUsing(function ($record) {
                        $avg = $record->total_quantity > 0 ? $record->total_profit / $record->total_quantity : 0;

                        return number_format($avg, 2).' ج.م';
                    }),
            ])
            ->defaultSort('total_sales', 'desc')
            ->paginated([5, 10, 25])
            ->striped();
    }

    private function getCategoryPerformance(): Builder
    {
        $filterType = $this->pageFilters['filterType'] ?? 'period';
        $startDate = $this->pageFilters['startDate'] ?? now()->subDays(30)->startOfDay()->toDateString();
        $endDate = $this->pageFilters['endDate'] ?? now()->endOfDay()->toDateString();
        $shiftIds = $filterType === 'shifts' ? ($this->pageFilters['shifts'] ?? []) : null;

        return $this->productsReportService->getCategoryPerformance(
            $startDate,
            $endDate,
            $shiftIds,
            ProductsSalesReportService::nullableFilterId($this->pageFilters['mainCategoryId'] ?? null),
            ProductsSalesReportService::nullableFilterId($this->pageFilters['subCategoryId'] ?? null),
            $this->categoryMode,
        );
    }
}
