<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Filament\Exports\PeriodShiftOrdersExporter;
use App\Filament\Widgets\Concerns\ScopedOrderPayments;
use App\Models\Order;
use App\Orders\OrderTypeCatalog;
use App\Services\PrintService;
use App\Services\ShiftsReportService;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class PeriodShiftOrdersTable extends BaseWidget
{
    use InteractsWithPageFilters;
    use ScopedOrderPayments;

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
     * @param  array  $filter  like ['status' => 'completed']
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
        [$startDate, $endDate, $shiftIds] = $this->shiftsReportService->scopeFromFilters($this->pageFilters);
        $scope = $this->shiftsReportService->shiftIdsQuery($startDate, $endDate, $shiftIds);

        $query = Order::query()
            ->with(['customer', 'user', 'shift', ...$this->scopedPaymentsEagerLoad($scope)])
            ->whereIn('shift_id', clone $scope);

        return $table
            ->query($query)
            // Anchor for the report cards: they scroll to this table.
            ->extraAttributes(['id' => 'orders_table', 'style' => 'scroll-margin-top: 5rem'])
            ->headerActions([
                ExportAction::make()
                    ->label('تصدير الاوردرات')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->exporter(PeriodShiftOrdersExporter::class)
                    // The exporter scopes payments itself (from these options), so the export job gets a
                    // plain eager load instead of the table's closure-constrained one.
                    ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['customer', 'user', 'shift', 'payments']))
                    ->options(['scope' => [$startDate, $endDate, $shiftIds]])
                    ->fileName(fn () => 'period-shift-orders-'.now()->format('Y-m-d-H-i-s').'.xlsx'),
            ])
            ->columns([
                TextColumn::make('id')
                    ->label('الرقم المرجعي')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('order_number')
                    ->label('رقم الطلب')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->color('primary'),

                TextColumn::make('shift.start_at')
                    ->label(label: 'الشفت')
                    ->dateTime('d/m H:i')
                    ->sortable()
                    ->color('gray')
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge(),

                TextColumn::make('type')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => app(OrderTypeCatalog::class)->display((string) $state)->label)
                    ->color(fn (mixed $state): string => app(OrderTypeCatalog::class)->display((string) $state)->color)
                    ->icon(fn (mixed $state): string => app(OrderTypeCatalog::class)->display((string) $state)->icon),

                TextColumn::make('customer.name')
                    ->label('العميل')
                    ->searchable()
                    ->default('غير محدد')
                    ->color('gray'),

                TextColumn::make('sub_total')
                    ->label('المجموع الفرعي')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' جنيه')
                    ->alignCenter(),

                TextColumn::make('tax')
                    ->label('الضريبة')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' جنيه')
                    ->alignCenter()
                    ->toggleable(),

                TextColumn::make('service')
                    ->label('الخدمة')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' جنيه')
                    ->alignCenter()
                    ->toggleable(),

                TextColumn::make('discount')
                    ->label('الخصم')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' جنيه')
                    ->color('danger')
                    ->alignCenter()
                    ->toggleable(),

                TextColumn::make('total')
                    ->label('الإجمالي')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' جنيه')
                    ->color('success')
                    ->weight('bold')
                    ->alignCenter(),

                TextColumn::make('profit')
                    ->label('الربح')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' جنيه')
                    ->color('success')
                    ->alignCenter()
                    ->toggleable(),

                TextColumn::make('payments')
                    ->label('طرق الدفع')
                    ->state(function ($record) {
                        $methods = $record->payments
                            ->pluck('method')
                            ->map(fn ($method) => $method->label())
                            ->unique()
                            ->implode(', ');

                        return $methods ?: 'غير محدد';
                    })
                    ->color('primary')
                    ->toggleable(),

                TextColumn::make('cash')
                    ->label('مدفوع كاش')
                    ->state(function ($record) {
                        $amount = $record->payments
                            ->where('method', PaymentMethod::CASH)
                            ->sum('amount');

                        return $amount > 0 ? number_format($amount, 2) : 'غير محدد';
                    })
                    ->color('primary')
                    ->toggleable(),

                TextColumn::make('card')
                    ->label('مدفوع فيزا')
                    ->state(function ($record) {
                        $amount = $record->payments
                            ->where('method', PaymentMethod::CARD)
                            ->sum('amount');

                        return $amount > 0 ? number_format($amount, 2) : 'غير محدد';
                    })
                    ->color('primary')
                    ->toggleable(),

                TextColumn::make('talabat_card')
                    ->label('مدفوع بطاقة طلبات')
                    ->state(function ($record) {
                        $amount = $record->payments
                            ->where('method', PaymentMethod::TALABAT_CARD)
                            ->sum('amount');

                        return $amount > 0 ? number_format($amount, 2) : 'غير محدد';
                    })
                    ->color('primary')
                    ->toggleable(),

                ...$this->scopedPaymentColumns('الفترة'),

                TextColumn::make('user.name')
                    ->label('الموظف')
                    ->searchable()
                    ->color('gray')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('وقت الإنشاء')
                    ->dateTime('d/m/Y H:i')
                    ->color('gray')
                    ->alignCenter()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(OrderStatus::class),

                SelectFilter::make('type')
                    ->label('النوع')
                    ->options(fn (): array => app(OrderTypeCatalog::class)->options()),

                $this->scopedPaymentMethodFilter($scope),

                $this->paymentStatusFilter(),

                $this->hasCreditFilter($scope),

                TernaryFilter::make('has_discount')
                    ->label('يحتوي على خصم')
                    ->queries(
                        true: fn (Builder $query) => $query->where('discount', '>', 0),
                        false: fn (Builder $query) => $query->where('discount', '<=', 0),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->paginated([10, 25, 50])
            ->emptyStateHeading('لا توجد طلبات')
            ->emptyStateDescription('لم يتم العثور على أي طلبات في الفترة المحددة.')
            ->emptyStateIcon('heroicon-o-shopping-cart')
            ->recordActions([
                ViewAction::make()
                    ->label('عرض')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Order $record): string => route('filament.admin.resources.orders.view', $record))
                    ->openUrlInNewTab(),
                Action::make('print')
                    ->label('طباعة')
                    ->icon('heroicon-o-printer')
                    ->color('primary')
                    ->action(function ($record) {
                        app(PrintService::class)->printOrderReceipt($record);
                    }),
            ])
            ->recordAction(ViewAction::class)
            ->recordUrl(fn (Order $record): string => route('filament.admin.resources.orders.view', $record))
            ->toolbarActions([]);
    }
}
