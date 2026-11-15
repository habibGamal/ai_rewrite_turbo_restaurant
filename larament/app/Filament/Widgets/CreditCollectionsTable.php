<?php

namespace App\Filament\Widgets;

use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Payment;
use App\Services\ShiftsReportService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * "تحصيلات الآجل": money taken in the report's shifts for orders that belong to other shifts.
 * Its total equals the "تحصيلات آجل سابقة" card, so every payment card can be traced to a row.
 */
abstract class CreditCollectionsTable extends BaseWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'تحصيلات الآجل من شفتات أخرى';

    protected ShiftsReportService $shiftsReportService;

    public function boot(): void
    {
        $this->shiftsReportService = app(ShiftsReportService::class);
    }

    /**
     * The shifts this report covers; null when there is nothing to show.
     */
    abstract protected function reportScope(): ?QueryBuilder;

    public function table(Table $table): Table
    {
        $scope = $this->reportScope();

        $query = Payment::query()->with(['order.customer', 'order.shift', 'order.payments', 'shift']);

        if ($scope === null) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereIn('payments.shift_id', clone $scope)
                ->whereHas('order', fn (Builder $order) => $order->whereNotIn('orders.shift_id', clone $scope));
        }

        return $table
            ->query($query)
            ->extraAttributes(['id' => 'credit_collections_table', 'style' => 'scroll-margin-top: 5rem'])
            ->description('مبالغ تم تحصيلها في هذا التقرير عن طلبات بيعت في شفتات أخرى (الدفع الآجل)')
            ->columns([
                TextColumn::make('order.id')
                    ->label('الرقم المرجعي')
                    ->searchable(),

                TextColumn::make('order.order_number')
                    ->label('رقم الطلب')
                    ->weight('medium')
                    ->color('primary'),

                TextColumn::make('order.shift.start_at')
                    ->label('شفت البيع')
                    ->dateTime('d/m/Y H:i')
                    ->color('gray'),

                TextColumn::make('order.customer.name')
                    ->label('العميل')
                    ->default('غير محدد')
                    ->searchable(),

                TextColumn::make('order.total')
                    ->label('إجمالي الطلب')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' جنيه')
                    ->alignCenter(),

                TextColumn::make('amount')
                    ->label('المبلغ المحصل')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' جنيه')
                    ->color('success')
                    ->weight('bold')
                    ->alignCenter()
                    ->summarize(\Filament\Tables\Columns\Summarizers\Sum::make()->label('الإجمالي')->numeric(decimalPlaces: 2)),

                TextColumn::make('method')
                    ->label('طريقة الدفع')
                    ->badge(),

                TextColumn::make('remaining_now')
                    ->label('المتبقي الآن')
                    ->state(function (Payment $record): float {
                        $order = $record->order;

                        if (! $order instanceof Order) {
                            return 0.0;
                        }

                        return max(0.0, round((float) $order->total - (float) $order->payments->sum('amount'), 2));
                    })
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' جنيه')
                    ->color(fn (mixed $state): string => (float) $state > 0 ? 'danger' : 'gray')
                    ->alignCenter(),

                TextColumn::make('shift.start_at')
                    ->label('شفت التحصيل')
                    ->dateTime('d/m/Y H:i')
                    ->color('gray')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('وقت التحصيل')
                    ->dateTime('d/m/Y H:i')
                    ->color('gray')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('method')
                    ->label('طريقة الدفع')
                    ->options(PaymentMethod::class),
            ])
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->paginated([10, 25, 50])
            ->emptyStateHeading('لا توجد تحصيلات آجل')
            ->emptyStateDescription('لم يتم تحصيل أي مبالغ عن طلبات من شفتات أخرى.')
            ->emptyStateIcon('heroicon-o-banknotes')
            ->recordUrl(fn (Payment $record): ?string => $record->order_id
                ? route('filament.admin.resources.orders.view', $record->order_id)
                : null)
            ->openRecordUrlInNewTab();
    }
}
