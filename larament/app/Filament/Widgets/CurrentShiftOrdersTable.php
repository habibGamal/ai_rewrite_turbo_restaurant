<?php

namespace App\Filament\Widgets;

use App\Models\Shift;
use App\Models\Order;
use App\Services\ShiftsReportService;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Filament\Exports\CurrentShiftOrdersExporter;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Actions\ExportAction;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class CurrentShiftOrdersTable extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'الاوردرات';

    protected ShiftsReportService $shiftsReportService;

    protected $listeners = ['filterUpdate' => 'updateTableFilters'];

    public function boot(): void
    {
        $this->shiftsReportService = app(ShiftsReportService::class);
    }


    /**
     * /
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
        $currentShift = $this->getCurrentShift();

        if (!$currentShift) {
            $query = Order::query()->where('id', 0); // Empty query
        } else {
            $query = Order::query()
                ->where('shift_id', $currentShift->id)
                ->with(['customer', 'user', 'payments'])
                ->latest();
        }

        return $table
            ->query($query)
            ->headerActions([
                ExportAction::make()
                    ->label('تصدير الاوردرات')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->exporter(CurrentShiftOrdersExporter::class)
                    ->modifyQueryUsing(function (Builder $query) {
                        $currentShift = $this->getCurrentShift();
                        if ($currentShift) {
                            return $query->where('shift_id', $currentShift->id)
                                ->with(['customer', 'user', 'payments']);
                        }
                        return $query->where('id', 0);
                    })
                    ->fileName(fn () => 'current-shift-orders-' . now()->format('Y-m-d-H-i-s') . '.xlsx')
                    ->visible(fn () => $this->getCurrentShift() !== null),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label('رقم الطلب')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->color('primary'),

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
                        // dd($record->payments);
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
                        $methods = $record->payments
                            ->where('method', PaymentMethod::CASH)
                            ->pluck('amount')
                            ->sum();
                        return $methods ?: 'غير محدد';
                    })
                    ->color('primary')
                    ->toggleable(),
                    Tables\Columns\TextColumn::make('card')
                        ->label('مدفوع فيزا')
                        ->state(function ($record) {
                            $methods = $record->payments
                                ->where('method', PaymentMethod::CARD)
                                ->pluck('amount')
                                ->sum();
                            return $methods ?: 'غير محدد';
                        })
                        ->color('primary')
                        ->toggleable(),

                    Tables\Columns\TextColumn::make('talabat_card')
                        ->label('مدفوع بطاقة طلبات')
                        ->state(function ($record) {
                            $methods = $record->payments
                                ->where('method', PaymentMethod::TALABAT_CARD)
                                ->pluck('amount')
                                ->sum();
                            return $methods ?: 'غير محدد';
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
            ->poll('30s')
            ->emptyStateHeading('لا توجد طلبات')
            ->emptyStateDescription('لم يتم العثور على أي طلبات في الشفت الحالي.')
            ->emptyStateIcon('heroicon-o-shopping-cart')
            ->recordAction(null)
            ->recordUrl(null)
            ->bulkActions([]);
    }



    private function getCurrentShift(): ?Shift
    {
        return $this->shiftsReportService->getCurrentShift();
    }
}
