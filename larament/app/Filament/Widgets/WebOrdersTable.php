<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Filament\Exports\WebOrdersExporter;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Actions\ExportAction;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class WebOrdersTable extends BaseWidget
{
    protected static bool $isLazy = false;
    protected static ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'أوردرات الويب';

    protected $listeners = ['filterUpdate' => 'updateTableFilters'];

    /**
     * @param array $filter like ['status' => 'completed']
     * @return void
     */
    public function updateTableFilters(array $filter): void
    {
        $key = array_key_first($filter);
        $value = $filter[$key];
        $this->resetTableFiltersForm();
        $this->tableFilters[$key]['value'] = $value;
    }

    public function table(Table $table): Table
    {
        $statuses = [
            OrderStatus::PENDING,
            OrderStatus::PROCESSING,
            OrderStatus::OUT_FOR_DELIVERY
        ];

        $query = Order::query()
            ->whereIn('type', [OrderType::WEB_TAKEAWAY , OrderType::WEB_DELIVERY])
            ->whereIn('status', $statuses)
            ->with(['customer', 'user', 'payments', 'driver']);

        return $table
            ->query($query)
            ->headerActions([
                ExportAction::make()
                    ->label('تصدير الأوردرات')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->exporter(WebOrdersExporter::class)
                    ->extraAttributes([
                        'id' => 'web_orders_table',
                    ])
                    ->modifyQueryUsing(function (Builder $query) use ($statuses) {
                        return $query->where('type', OrderType::DELIVERY)
                            ->whereIn('status', $statuses)
                            ->with(['customer', 'user', 'payments', 'driver']);
                    })
                    ->fileName(fn() => 'web-orders-' . now()->format('Y-m-d-H-i-s') . '.xlsx'),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('الرقم المرجعي')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('order_number')
                    ->label('رقم الطلب')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->color('primary'),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge(),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('العميل')
                    ->searchable()
                    ->default('غير محدد')
                    ->color('gray'),

                Tables\Columns\TextColumn::make('customer.phone')
                    ->label('رقم الهاتف')
                    ->searchable()
                    ->default('غير محدد')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('web_payment_method')
                    ->label('طريقة الدفع (ويب)')
                    ->state(function ($record) {
                        $method = $record->web_preferences['payment_method'] ?? null;
                        return match($method) {
                            'cash' => 'كاش',
                            'card' => 'فيزا',
                            'talabat_card' => 'بطاقة طلبات',
                            default => 'غير محدد'
                        };
                    })
                    ->badge()
                    ->color(fn ($state) => match($state) {
                        'كاش' => 'success',
                        'فيزا' => 'info',
                        'بطاقة طلبات' => 'warning',
                        default => 'gray'
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('transaction_id')
                    ->label('رقم المعاملة')
                    ->state(fn ($record) => $record->web_preferences['transaction_id'] ?? 'غير محدد')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('web_preferences->transaction_id', 'like', "%{$search}%");
                    })
                    ->copyable()
                    ->copyMessage('تم نسخ رقم المعاملة')
                    ->copyMessageDuration(1500)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('driver.name')
                    ->label('السائق')
                    ->searchable()
                    ->default('غير محدد')
                    ->color('info')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('sub_total')
                    ->label('المجموع الفرعي')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' جنيه')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('tax')
                    ->label('الضريبة')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' جنيه')
                    ->alignCenter()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('service')
                    ->label('الخدمة')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' جنيه')
                    ->alignCenter()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('discount')
                    ->label('الخصم')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' جنيه')
                    ->color('danger')
                    ->alignCenter()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total')
                    ->label('الإجمالي')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' جنيه')
                    ->color('success')
                    ->weight('bold')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('profit')
                    ->label('الربح')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' جنيه')
                    ->color('success')
                    ->alignCenter()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('payments')
                    ->label('طرق الدفع')
                    ->state(function ($record) {
                        $methods = $record->payments
                            ->pluck('method')
                            ->map(fn($method) => $method->label())
                            ->unique()
                            ->implode(', ');
                        return $methods ?: 'غير محدد';
                    })
                    ->color('primary')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('cash')
                    ->label('مدفوع كاش')
                    ->state(function ($record) {
                        $amount = $record->payments
                            ->where('method', PaymentMethod::CASH)
                            ->sum('amount');
                        return $amount > 0 ? number_format($amount, 2) . ' جنيه' : 'غير محدد';
                    })
                    ->color('primary')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('card')
                    ->label('مدفوع فيزا')
                    ->state(function ($record) {
                        $amount = $record->payments
                            ->where('method', PaymentMethod::CARD)
                            ->sum('amount');
                        return $amount > 0 ? number_format($amount, 2) . ' جنيه' : 'غير محدد';
                    })
                    ->color('primary')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('الموظف')
                    ->searchable()
                    ->color('gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('وقت الإنشاء')
                    ->dateTime('d/m/Y H:i')
                    ->color('gray')
                    ->alignCenter()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        OrderStatus::PENDING->value => OrderStatus::PENDING->label(),
                        OrderStatus::PROCESSING->value => OrderStatus::PROCESSING->label(),
                        OrderStatus::OUT_FOR_DELIVERY->value => OrderStatus::OUT_FOR_DELIVERY->label(),
                    ]),

                Tables\Filters\SelectFilter::make('web_payment_method')
                    ->label('طريقة الدفع (ويب)')
                    ->options([
                        'cash' => 'كاش',
                        'card' => 'فيزا',
                        'talabat_card' => 'بطاقة طلبات',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['value'],
                            fn(Builder $query, $value): Builder => $query->where('web_preferences->payment_method', $value)
                        );
                    }),

                Tables\Filters\SelectFilter::make('payment_method')
                    ->label('طريقة الدفع')
                    ->options(PaymentMethod::class)
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['value'],
                            fn(Builder $query, $value): Builder => $query->whereHas('payments', function (Builder $subQuery) use ($value) {
                                $subQuery->where('method', $value);
                            })
                        );
                    }),

                Tables\Filters\TernaryFilter::make('has_discount')
                    ->label('يحتوي على خصم')
                    ->queries(
                        true: fn(Builder $query) => $query->where('discount', '>', 0),
                        false: fn(Builder $query) => $query->where('discount', '<=', 0),
                        blank: fn(Builder $query) => $query,
                    ),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('created_from')
                            ->label('من تاريخ'),
                        \Filament\Forms\Components\DatePicker::make('created_until')
                            ->label('إلى تاريخ'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn(Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn(Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->paginated([10, 25, 50, 100])
            ->poll('30s')
            ->emptyStateHeading('لا توجد أوردرات')
            ->emptyStateDescription('لم يتم العثور على أي أوردرات ويب.')
            ->emptyStateIcon('heroicon-o-shopping-cart')
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('عرض')
                    ->icon('heroicon-o-eye')
                    ->url(fn(Order $record): string => route('filament.admin.resources.orders.view', $record))
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('print')
                    ->label('طباعة')
                    ->icon('heroicon-o-printer')
                    ->color('primary')
                    ->action(function ($record) {
                        app(\App\Services\PrintService::class)->printOrderReceipt($record, []);
                    })
            ])
            ->recordAction(Tables\Actions\ViewAction::class)
            ->recordUrl(fn(Order $record): string => route('filament.admin.resources.orders.view', $record))
            ->bulkActions([]);
    }
}
