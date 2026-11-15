<?php

namespace App\Filament\Widgets;

use App\Filament\Exports\CashierCommissionTableExporter;
use App\Filament\Pages\Reports\CashierPerformanceDetails;
use App\Models\OrderItemChange;
use App\Models\User;
use App\Services\CashierCommissionReportService;
use App\Services\ShiftsReportService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

class CashierCommissionTableWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    use InteractsWithPageFilters;

    protected ShiftsReportService $shiftsReportService;

    protected CashierCommissionReportService $commissionService;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'تقرير عمولات وأداء الكاشير';

    public function boot(): void
    {
        $this->shiftsReportService = app(ShiftsReportService::class);
        $this->commissionService = app(CashierCommissionReportService::class);
    }

    public function getHeading(): string
    {
        return 'تقرير عمولات وأداء الكاشير';
    }

    public function table(Table $table): Table
    {
        $filterType = $this->pageFilters['filterType'] ?? 'period';
        $shiftIds = [];
        $startDate = null;
        $endDate = null;

        if ($filterType === 'shifts') {
            $shiftIds = $this->pageFilters['shifts'] ?? [];
            $query = ! empty($shiftIds)
                ? $this->commissionService->getCashierCommissionQuery($shiftIds, null, null)
                : User::query()->whereRaw('1 = 0');
        } else {
            $startDate = Carbon::parse($this->pageFilters['startDate'] ?? now()->subDays(6)->startOfDay()->toDateString())->startOfDay();
            $endDate = Carbon::parse($this->pageFilters['endDate'] ?? now()->endOfDay()->toDateString())->endOfDay();

            $shiftIds = $this->shiftsReportService->getShiftsInPeriodQuery($startDate->toDateString(), $endDate->toDateString(), null)
                ->pluck('id')
                ->toArray();

            $query = ! empty($shiftIds)
                ? $this->commissionService->getCashierCommissionQuery($shiftIds, $startDate, $endDate)
                : User::query()->whereRaw('1 = 0');
        }

        return $table
            ->query($query)
            ->headerActions([
                ExportAction::make()
                    ->label('تصدير التقرير')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->exporter(CashierCommissionTableExporter::class)
                    ->formats([ExportFormat::Xlsx, ExportFormat::Csv])
                    ->fileName(fn () => 'cashier-commission-report-'.now()->format('Y-m-d-H-i-s').'.xlsx'),
            ])
            ->columns([
                TextColumn::make('name')
                    ->label('اسم الكاشير')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('orders_count')
                    ->label('الطلبات المنشأة')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color('primary')
                    ->toggleable(),

                TextColumn::make('orders_total_value')
                    ->label('إجمالي قيمة الطلبات')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).' ج.م')
                    ->sortable()
                    ->weight('bold')
                    ->toggleable(),

                TextColumn::make('orders_without_discount_count')
                    ->label('طلبات بدون خصم (عدد)')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color('success')
                    ->toggleable(),

                TextColumn::make('orders_without_discount_value')
                    ->label('طلبات بدون خصم (قيمة)')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).' ج.م')
                    ->sortable()
                    ->color('success')
                    ->toggleable(),

                TextColumn::make('orders_with_discount_count')
                    ->label('طلبات بخصم (عدد)')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color('warning')
                    ->toggleable(),

                TextColumn::make('orders_with_discount_value')
                    ->label('طلبات بخصم (قيمة)')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).' ج.م')
                    ->sortable()
                    ->color('warning')
                    ->toggleable(),

                TextColumn::make('total_discount_amount')
                    ->label('إجمالي الخصم الممنوح')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).' ج.م')
                    ->sortable()
                    ->color('danger')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('items_added_count')
                    ->label('الأصناف المضافة')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color('info')
                    ->toggleable(),

                TextColumn::make('items_added_value')
                    ->label('قيمة الأصناف المضافة')
                    ->formatStateUsing(fn ($state) => '+'.number_format((float) $state, 2).' ج.م')
                    ->color('info')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('items_removed_count')
                    ->label('الأصناف المحذوفة')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color('danger')
                    ->toggleable(),

                TextColumn::make('items_removed_value')
                    ->label('قيمة الأصناف المحذوفة')
                    ->formatStateUsing(fn ($state) => '-'.number_format((float) $state, 2).' ج.م')
                    ->color('danger')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('net_items_value')
                    ->label('صافي قيمة التعديلات')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).' ج.م')
                    ->color(fn ($state) => (float) $state >= 0 ? 'success' : 'danger')
                    ->sortable()
                    ->toggleable(),
            ])
            ->recordActions([
                Action::make('view_details')
                    ->label('تفاصيل الأداء')
                    ->icon('heroicon-o-chart-bar')
                    ->color('primary')
                    ->url(fn (User $record): string => CashierPerformanceDetails::getUrl(['record' => $record->id]))
                    ->openUrlInNewTab(false),

                Action::make('view_changes')
                    ->label('تعديلات الأصناف')
                    ->icon('heroicon-o-list-bullet')
                    ->color('warning')
                    ->modalHeading(fn (User $record): string => 'سجل تعديلات الأصناف - '.$record->name)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('إغلاق')
                    ->modalContent(function (User $record) use ($shiftIds, $startDate, $endDate) {
                        $changesQuery = OrderItemChange::query()
                            ->where('order_item_changes.user_id', $record->id)
                            ->with('order');

                        if (! empty($shiftIds)) {
                            $changesQuery->join('orders', 'orders.id', '=', 'order_item_changes.order_id')
                                ->whereIn('orders.shift_id', $shiftIds)
                                ->select('order_item_changes.*');
                        } elseif ($startDate && $endDate) {
                            $changesQuery->whereBetween('order_item_changes.created_at', [$startDate, $endDate]);
                        }

                        $changes = $changesQuery->orderBy('order_item_changes.created_at', 'desc')->get();

                        return view('filament.widgets.cashier-item-changes-modal', [
                            'cashier' => $record,
                            'changes' => $changes,
                        ]);
                    }),

                Action::make('view_orders')
                    ->label('الطلبات')
                    ->icon('heroicon-o-shopping-cart')
                    ->color('info')
                    ->url(fn (User $record): string => url("/admin/orders?tableFilters[user_id][value]={$record->id}"))
                    ->openUrlInNewTab(false),
            ])
            ->striped()
            ->paginated(false);
    }
}
