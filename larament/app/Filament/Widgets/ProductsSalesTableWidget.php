<?php

namespace App\Filament\Widgets;

use App\Enums\ProductType;
use App\Filament\Exports\ProductsSalesTableExporter;
use App\Services\ProductsSalesReportService;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class ProductsSalesTableWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected static ?string $heading = 'تفاصيل أداء المنتجات';

    protected ProductsSalesReportService $productsReportService;

    public function boot(): void
    {
        $this->productsReportService = app(ProductsSalesReportService::class);
    }

    public function table(Table $table): Table
    {
        $filterType = $this->pageFilters['filterType'] ?? 'period';
        $startDate = $this->pageFilters['startDate'] ?? now()->subDays(30)->startOfDay()->toDateString();
        $endDate = $this->pageFilters['endDate'] ?? now()->endOfDay()->toDateString();
        $shiftIds = $filterType === 'shifts' ? ($this->pageFilters['shifts'] ?? []) : null;
        $mainCategoryId = ProductsSalesReportService::nullableFilterId($this->pageFilters['mainCategoryId'] ?? null);
        $subCategoryId = ProductsSalesReportService::nullableFilterId($this->pageFilters['subCategoryId'] ?? null);

        return $table
            ->query(
                $this->productsReportService->getProductsSalesPerformanceQuery(
                    $startDate,
                    $endDate,
                    $shiftIds,
                    $mainCategoryId,
                    $subCategoryId,
                )
            )
            ->headerActions([
                ExportAction::make()
                    ->label('تصدير تقرير أداء المنتجات')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->exporter(ProductsSalesTableExporter::class)
                    ->modifyQueryUsing(function (Builder $query, array $options): Builder {
                        // Return a fresh query from the service instead of modifying the table's query
                        return app(ProductsSalesReportService::class)->getProductsSalesPerformanceQuery(
                            $options['startDate'] ?? now()->subDays(30)->startOfDay()->toDateString(),
                            $options['endDate'] ?? now()->endOfDay()->toDateString(),
                            $options['shiftIds'] ?? null,
                            ProductsSalesReportService::nullableFilterId($options['mainCategoryId'] ?? null),
                            ProductsSalesReportService::nullableFilterId($options['subCategoryId'] ?? null),
                        );
                    })
                    ->options([
                        'startDate' => $startDate,
                        'endDate' => $endDate,
                        'shiftIds' => $shiftIds,
                        'mainCategoryId' => $mainCategoryId,
                        'subCategoryId' => $subCategoryId,
                    ])
                    ->fileName(fn () => 'products-sales-performance-'.now()->format('Y-m-d-H-i-s').'.xlsx'),
            ])
            ->columns([
                TextColumn::make('name')
                    ->label('اسم المنتج')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('products.name', 'like', "%{$search}%");
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('products.name', $direction);
                    }),

                TextColumn::make('main_category_name')
                    ->label('التصنيف الرئيسي')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('main_categories.name', 'like', "%{$search}%");
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('main_categories.name', $direction);
                    })
                    ->default('غير مصنف'),

                TextColumn::make('sub_category_name')
                    ->label('التصنيف الفرعي')
                    ->placeholder('أصناف التصنيف الرئيسي')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('sub_category_name', $direction);
                    }),

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
                    ->state(
                        fn ($record) => $record->total_sales > 0
                            ? number_format(($record->total_profit / $record->total_sales) * 100, 1).'%'
                            : '0%'
                    ),

                ...array_map(fn (array $type) => TextColumn::make('type_'.$type['code'].'_sales')
                    ->label($type['label'])->money('EGP')->sortable(),
                    $this->productsReportService->getReportOrderTypes($startDate, $endDate, $shiftIds)),
            ])
            ->defaultSort('total_sales', 'desc')
            ->paginated([10, 25, 50, 100])
            ->striped()
            ->recordActions([
                ViewAction::make()
                    ->label('عرض المنتج')
                    ->icon('heroicon-o-eye')
                    ->url(function ($record): string {
                        // Determine the appropriate resource based on product type
                        $resourceRoute = match ($record->type) {
                            ProductType::RawMaterial => 'raw-material-products',
                            ProductType::Manufactured => 'manufactured-products',
                            ProductType::Consumable => 'consumable-products',
                            default => 'consumable-products', // fallback
                        };

                        return route("filament.admin.resources.{$resourceRoute}.view", $record->id);
                    })
                    ->openUrlInNewTab(),
            ])
            ->recordAction(ViewAction::class)
            ->recordUrl(function ($record): string {
                // Determine the appropriate resource based on product type
                $resourceRoute = match ($record->type) {
                    'raw_material' => 'raw-material-products',
                    'manufactured' => 'manufactured-products',
                    'consumable' => 'consumable-products',
                    default => 'consumable-products', // fallback
                };

                return route("filament.admin.resources.{$resourceRoute}.view", $record->id);
            });
    }
}
