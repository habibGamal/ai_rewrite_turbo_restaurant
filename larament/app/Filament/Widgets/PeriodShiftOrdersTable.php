<?php

namespace App\Filament\Widgets;

use App\Services\ShiftsReportService;
use App\Models\Order;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Filament\Exports\PeriodShiftOrdersExporter;
use Carbon\Carbon;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Actions\ExportAction;
use Filament\Widgets\TableWidget as BaseWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Database\Eloquent\Builder;

class PeriodShiftOrdersTable extends BaseWidget
{
    use InteractsWithPageFilters;


    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'الاوردرات';

    protected ShiftsReportService $shiftsReportService;

    protected $listeners = ['filterUpdate' => 'updateTableFilters'];

    public function boot(): void
    {
        $this->shiftsReportService = app(ShiftsReportService::class);
    }

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
        $filterType = $this->filters['filterType'] ?? 'period';

        if ($filterType === 'shifts') {
            $shiftIds = $this->filters['shifts'] ?? [];
            $query = Order::query()
                ->with(['customer', 'user', 'payments', 'shift'])
                ->when(!empty($shiftIds), function (Builder $query) use ($shiftIds) {
                    return $query->whereIn('shift_id', $shiftIds);
                });
        } else {
            $startDate = $this->filters['startDate'];
            $endDate = $this->filters['endDate'];
            $query = Order::query()
                ->with(['customer', 'user', 'payments', 'shift'])
                ->whereHas('shift', function (Builder $query) use ($startDate, $endDate) {
                    $query->whereBetween('created_at', [
                        Carbon::parse($startDate)->startOfDay(),
                        Carbon::parse($endDate)->endOfDay()
                    ]);
                });
        }

        return $table
            ->query($query)
            ->headerActions([
                ExportAction::make()
                    ->label('تصدير الاوردرات')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->exporter(PeriodShiftOrdersExporter::class)
                    ->extraAttributes([
                        'id' => 'orders_table',
                    ])
                    ->fileName(fn() => 'period-shift-orders-' . now()->format('Y-m-d-H-i-s') . '.xlsx'),
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

                Tables\Columns\TextColumn::make('shift.start_at')
                    ->label(label: 'الشفت')
                    ->dateTime('d/m H:i')
                    ->sortable()
                    ->color('gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge(),

                Tables\Columns\TextColumn::make('type')
                    ->label('النوع')
                    ->badge(),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('العميل')
                    ->searchable()
                    ->default('غير محدد')
                    ->color('gray'),

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
                        return $amount > 0 ? number_format($amount, 2) : 'غير محدد';
                    })
                    ->color('primary')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('card')
                    ->label('مدفوع فيزا')
                    ->state(function ($record) {
                        $amount = $record->payments
                            ->where('method', PaymentMethod::CARD)
                            ->sum('amount');
                        return $amount > 0 ? number_format($amount, 2) : 'غير محدد';
                    })
                    ->color('primary')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('talabat_card')
                    ->label('مدفوع بطاقة طلبات')
                    ->state(function ($record) {
                        $amount = $record->payments
                            ->where('method', PaymentMethod::TALABAT_CARD)
                            ->sum('amount');
                        return $amount > 0 ? number_format($amount, 2) : 'غير محدد';
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
                    ->options(OrderStatus::class),

                Tables\Filters\SelectFilter::make('type')
                    ->label('النوع')
                    ->options(OrderType::class),

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
            ])
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->paginated([10, 25, 50])
            ->emptyStateHeading('لا توجد طلبات')
            ->emptyStateDescription('لم يتم العثور على أي طلبات في الفترة المحددة.')
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
