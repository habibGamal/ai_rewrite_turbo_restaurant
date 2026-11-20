<?php

namespace App\Filament\Exports;

use App\Enums\OrderStatus;
use App\Filament\Widgets\ProductsSalesTableWidget;
use App\Models\Order;
use App\Models\Product;
use App\Services\ProductsSalesReportService;
use App\Services\Reports\ReportOrderTypesService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class ProductsSalesTableExporter extends Exporter
{
    protected static ?string $model = Product::class;

    public static function getColumns(): array
    {
        $component = app('livewire')->current();
        $filters = $component instanceof ProductsSalesTableWidget ? ($component->pageFilters ?? []) : null;
        $types = $filters === null
            ? app(ReportOrderTypesService::class)->forQuery(Order::query()->where('status', OrderStatus::COMPLETED))
            : app(ProductsSalesReportService::class)->getReportOrderTypes($filters['startDate'] ?? now()->subDays(30)->toDateString(), $filters['endDate'] ?? now()->toDateString(), ($filters['filterType'] ?? 'period') === 'shifts' ? ($filters['shifts'] ?? []) : null);

        return [
            ExportColumn::make('name')
                ->label('اسم المنتج'),

            ExportColumn::make('main_category_name')
                ->label('التصنيف الرئيسي')
                ->state(function ($record) {
                    return $record->main_category_name ?: ($record->category_name ?: 'غير مصنف');
                }),

            ExportColumn::make('sub_category_name')
                ->label('التصنيف الفرعي')
                ->state(function ($record) {
                    return $record->sub_category_name ?: 'أصناف التصنيف الرئيسي';
                }),

            ExportColumn::make('category_name')
                ->label('التصنيف')
                ->state(function ($record) {
                    return $record->category_name ?: 'غير مصنف';
                }),

            ExportColumn::make('total_quantity')
                ->label('إجمالي الكمية')
                ->state(function ($record) {
                    return $record->total_quantity ?? 0;
                }),

            ExportColumn::make('total_sales')
                ->label('إجمالي المبيعات (ج.م)')
                ->state(function ($record) {
                    return number_format($record->total_sales ?? 0, 2);
                }),

            ExportColumn::make('total_profit')
                ->label('إجمالي الربح (ج.م)')
                ->state(function ($record) {
                    return number_format($record->total_profit ?? 0, 2);
                }),

            ExportColumn::make('profit_margin')
                ->label('هامش الربح %')
                ->state(function ($record) {
                    $totalSales = $record->total_sales ?? 0;
                    $totalProfit = $record->total_profit ?? 0;

                    return $totalSales > 0 ? number_format(($totalProfit / $totalSales) * 100, 1).'%' : '0%';
                }),

            ...array_map(fn (array $type) => ExportColumn::make('type_'.$type['code'].'_sales')
                ->label($type['label'])
                ->state(fn ($record) => number_format($record->{'type_'.$type['code'].'_sales'} ?? 0, 2)),
                $types),

            // Additional performance metrics
            ExportColumn::make('unit_price')
                ->label('سعر الوحدة (ج.م)')
                ->state(function ($record) {
                    return number_format($record->price ?? 0, 2);
                }),

            ExportColumn::make('unit_cost')
                ->label('تكلفة الوحدة (ج.م)')
                ->state(function ($record) {
                    return number_format($record->cost ?? 0, 2);
                }),

            ExportColumn::make('average_selling_price')
                ->label('متوسط سعر البيع (ج.م)')
                ->state(function ($record) {
                    $totalQuantity = $record->total_quantity ?? 0;
                    $totalSales = $record->total_sales ?? 0;
                    $avgPrice = $totalQuantity > 0 ? $totalSales / $totalQuantity : 0;

                    return number_format($avgPrice, 2);
                }),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'تم إكمال تصدير تقرير أداء المنتجات وتم تصدير '.number_format($export->successful_rows).' '.($export->successful_rows == 1 ? 'منتج' : 'منتج').'.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' فشل في تصدير '.number_format($failedRowsCount).' '.($failedRowsCount == 1 ? 'منتج' : 'منتج').'.';
        }

        return $body;
    }
}
