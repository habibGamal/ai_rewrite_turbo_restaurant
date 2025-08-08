<?php

namespace App\Filament\Widgets;

use App\Services\ProductsSalesReportService;
use App\Filament\Exports\ProductsSalesTableExporter;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Actions\ExportAction;
use Filament\Widgets\TableWidget as BaseWidget;
use Filament\Tables\Columns\TextColumn;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
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
        $startDate = $this->filters['startDate'] ?? now()->subDays(30)->startOfDay()->toDateString();
        $endDate = $this->filters['endDate'] ?? now()->endOfDay()->toDateString();

        return $table
            ->query(
                $this->productsReportService->getProductsSalesPerformanceQuery(
                    $startDate,
                    $endDate
                )
            )
            ->headerActions([
                ExportAction::make()
                    ->label('تصدير تقرير أداء المنتجات')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->exporter(ProductsSalesTableExporter::class)
                    ->fileName(fn () => 'products-sales-performance-' . now()->format('Y-m-d-H-i-s') . '.xlsx'),
            ])
            ->columns([
                TextColumn::make('name')
                    ->label('اسم المنتج')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category_name')
                    ->label('التصنيف')
                    ->sortable()
                    ->default('غير مصنف'),

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
                        fn($record) => $record->total_sales > 0
                            ? number_format(($record->total_profit / $record->total_sales) * 100, 1) . '%'
                            : '0%'
                    ),

                // Order Type Performance
                TextColumn::make('dine_in_sales')
                    ->label('صالة')
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('takeaway_sales')
                    ->label('تيك أواي')
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('delivery_sales')
                    ->label('دليفري')
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('web_delivery_sales')
                    ->label('اونلاين دليفري')
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('web_takeaway_sales')
                    ->label('اونلاين تيك أواي')
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('talabat_sales')
                    ->label('طلبات')
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('companies_sales')
                    ->label('شركات')
                    ->money('EGP')
                    ->sortable(),
            ])
            ->defaultSort('total_sales', 'desc')
            ->paginated([10, 25, 50, 100])
            ->striped();
    }
}
