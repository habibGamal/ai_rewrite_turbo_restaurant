<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Filament\Exports\CurrentShiftOrdersExporter;
use App\Filament\Widgets\Concerns\ScopedOrderPayments;
use App\Filament\Widgets\Concerns\ScopesReportToTerminal;
use App\Models\Order;
use App\Models\Shift;
use App\Orders\OrderTypeCatalog;
use App\Services\FeatureService;
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

class CurrentShiftOrdersTable extends BaseWidget
{
    use InteractsWithPageFilters;
    use ScopedOrderPayments;
    use ScopesReportToTerminal;

    protected static bool $isLazy = false;

    protected static ?string $pollingInterval = null;

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
        $terminal = $this->reportTerminal();

        $currentShift = $this->getCurrentShift();

        $scope = $this->shiftsReportService->shiftIdsQuery(null, null, [$currentShift->id ?? 0]);

        if (! $currentShift) {
            $query = Order::query()->where('id', 0); // Empty query
        } else {
            $query = $terminal->orders(Order::query()
                ->where('shift_id', $currentShift->id)
                ->with(['customer', 'user', 'terminal', ...$this->scopedPaymentsEagerLoad($scope)]));
        }

        return $table
            ->query($query)
            // Anchor for the report cards: they scroll to this table.
            ->extraAttributes(['id' => 'orders_table', 'style' => 'scroll-margin-top: 5rem'])
            ->headerActions([
                ExportAction::make()
                    ->label('تصدير الاوردرات')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->exporter(CurrentShiftOrdersExporter::class)
                    ->modifyQueryUsing(function (Builder $query) {
                        $currentShift = $this->getCurrentShift();
                        if ($currentShift) {
                            return $this->reportTerminal()->orders($query->where('shift_id', $currentShift->id)
                                ->with(['customer', 'user', 'terminal', 'payments']));
                        }

                        return $query->where('id', 0);
                    })
                    ->options(fn (): array => ['shift_ids' => [$this->getCurrentShift()->id ?? 0]])
                    ->fileName(fn () => 'current-shift-orders-'.now()->format('Y-m-d-H-i-s').'.xlsx')
                    ->visible(fn () => $this->getCurrentShift() !== null),
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
                        // dd($record->payments);
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
                        $methods = $record->payments
                            ->where('method', PaymentMethod::CASH)
                            ->pluck('amount')
                            ->sum();

                        return $methods ?: 'غير محدد';
                    })
                    ->color('primary')
                    ->toggleable(),
                TextColumn::make('card')
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

                TextColumn::make('talabat_card')
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

                ...$this->scopedPaymentColumns('الشفت'),

                TextColumn::make('user.name')
                    ->label('الموظف')
                    ->searchable()
                    ->color('gray')
                    ->toggleable(),

                TextColumn::make('terminal.name')
                    ->label('منفذ البيع')
                    ->color('gray')
                    ->placeholder('—')
                    ->visible(fn (): bool => app(FeatureService::class)->multiTerminalEnabled())
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

                SelectFilter::make('terminal_id')
                    ->label('منفذ البيع')
                    ->relationship('terminal', 'name')
                    ->visible(fn (): bool => app(FeatureService::class)->multiTerminalEnabled()),

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
            ->poll('30s')
            ->emptyStateHeading('لا توجد طلبات')
            ->emptyStateDescription('لم يتم العثور على أي طلبات في الشفت الحالي.')
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
                        app(PrintService::class)->printOrderReceipt($record, []);
                    }),
            ])
            ->recordAction(ViewAction::class)
            ->recordUrl(fn (Order $record): string => route('filament.admin.resources.orders.view', $record))
            ->toolbarActions([]);
    }

    private function getCurrentShift(): ?Shift
    {
        return $this->shiftsReportService->getCurrentShift();
    }
}
