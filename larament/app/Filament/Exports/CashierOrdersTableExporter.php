<?php

namespace App\Filament\Exports;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Orders\OrderTypeCatalog;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class CashierOrdersTableExporter extends Exporter
{
    protected static ?string $model = Order::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('order_number')
                ->label('رقم الطلب'),

            ExportColumn::make('type')
                ->label('نوع الطلب')
                ->formatStateUsing(fn (mixed $state): string => app(OrderTypeCatalog::class)->display((string) $state)->label),

            ExportColumn::make('status')
                ->label('حالة الطلب')
                ->formatStateUsing(fn ($state) => $state instanceof OrderStatus ? $state->label() : (string) $state),

            ExportColumn::make('payment_status')
                ->label('حالة الدفع')
                ->formatStateUsing(fn ($state) => $state instanceof PaymentStatus ? $state->label() : (string) $state),

            ExportColumn::make('sub_total')
                ->label('المجموع الفرعي (ج.م)')
                ->state(fn (Order $record): string => number_format((float) ($record->sub_total ?? 0), 2)),

            ExportColumn::make('discount')
                ->label('الخصم (ج.م)')
                ->state(fn (Order $record): string => number_format((float) ($record->discount ?? 0), 2)),

            ExportColumn::make('total')
                ->label('الإجمالي (ج.م)')
                ->state(fn (Order $record): string => number_format((float) ($record->total ?? 0), 2)),

            ExportColumn::make('dine_table_number')
                ->label('الطاولة')
                ->state(fn (Order $record): string => $record->dine_table_number ?: '—'),

            ExportColumn::make('customer_name')
                ->label('العميل')
                ->state(function (Order $record): string {
                    $customer = $record->customer;

                    return ($customer instanceof Customer && $customer->name) ? $customer->name : '—';
                }),

            ExportColumn::make('created_at')
                ->label('تاريخ الإنشاء')
                ->formatStateUsing(fn ($state) => $state?->format('Y-m-d H:i') ?? ''),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'تم إكمال تصدير طلبات الكاشير وتم تصدير '.number_format($export->successful_rows).' '.($export->successful_rows == 1 ? 'طلب' : 'طلب').'.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' فشل في تصدير '.number_format($failedRowsCount).' '.($failedRowsCount == 1 ? 'طلب' : 'طلب').'.';
        }

        return $body;
    }
}
