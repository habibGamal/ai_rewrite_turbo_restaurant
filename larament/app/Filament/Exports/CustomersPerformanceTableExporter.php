<?php

namespace App\Filament\Exports;

use App\Enums\OrderStatus;
use App\Filament\Widgets\CustomersPerformanceTableWidget;
use App\Models\Customer;
use App\Models\Order;
use App\Services\CustomersPerformanceReportService;
use App\Services\Reports\ReportOrderTypesService;
use Carbon\Carbon;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class CustomersPerformanceTableExporter extends Exporter
{
    protected static ?string $model = Customer::class;

    public static function getColumns(): array
    {
        $component = app('livewire')->current();
        $filters = $component instanceof CustomersPerformanceTableWidget ? ($component->pageFilters ?? []) : null;
        [$defaultStart, $defaultEnd] = CustomersPerformanceReportService::defaultPeriod();
        $types = $filters === null
            ? app(ReportOrderTypesService::class)->forQuery(Order::query()->where('status', OrderStatus::COMPLETED))
            : app(CustomersPerformanceReportService::class)->getReportOrderTypes($filters['startDate'] ?? $defaultStart, $filters['endDate'] ?? $defaultEnd);

        $money = fn (string $attribute) => fn ($record): float => round((float) ($record->{$attribute} ?? 0), 2);

        return [
            ExportColumn::make('name')->label('اسم العميل'),
            ExportColumn::make('phone')->label('رقم الهاتف'),
            ExportColumn::make('region')->label('المنطقة')
                ->state(fn ($record) => $record->region ?: 'غير محدد'),
            ExportColumn::make('customer_segment')->label('تصنيف العميل'),
            ExportColumn::make('total_orders')->label('عدد الطلبات')
                ->state(fn ($record): int => (int) ($record->total_orders ?? 0)),
            ExportColumn::make('total_quantity')->label('إجمالي الكمية')
                ->state($money('total_quantity')),
            ExportColumn::make('total_sales')->label('صافي المبيعات (ج.م)')
                ->state($money('total_sales')),
            ExportColumn::make('total_profit')->label('صافي الربح (ج.م)')
                ->state($money('total_profit')),
            ExportColumn::make('profit_margin')->label('هامش الربح %')
                ->state(fn ($record): float => ($record->total_sales ?? 0) > 0
                    ? round($record->total_profit / $record->total_sales * 100, 1)
                    : 0.0),
            ExportColumn::make('avg_order_value')->label('متوسط قيمة الطلب (ج.م)')
                ->state($money('avg_order_value')),
            ExportColumn::make('total_discount')->label('الخصومات (ج.م)')
                ->state($money('total_discount')),
            ExportColumn::make('total_refunds')->label('المرتجعات (ج.م)')
                ->state($money('total_refunds')),
            ExportColumn::make('avg_days_between_orders')->label('متوسط الأيام بين الطلبات')
                ->state(fn ($record) => CustomersPerformanceTableWidget::avgDaysBetweenOrders($record)),
            ExportColumn::make('last_order_date')->label('آخر طلب')
                ->state(fn ($record) => $record->last_order_date ? Carbon::parse($record->last_order_date)->format('Y-m-d') : null),
            ExportColumn::make('first_order_date')->label('أول طلب في الفترة')
                ->state(fn ($record) => $record->first_order_date ? Carbon::parse($record->first_order_date)->format('Y-m-d') : null),

            ...array_map(fn (array $type) => ExportColumn::make('type_'.$type['code'].'_sales')
                ->label($type['label'])
                ->state($money('type_'.$type['code'].'_sales')),
                $types),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'تم إكمال تصدير تقرير أداء العملاء وتم تصدير '.number_format($export->successful_rows).' عميل.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' فشل في تصدير '.number_format($failedRowsCount).' عميل.';
        }

        return $body;
    }
}
