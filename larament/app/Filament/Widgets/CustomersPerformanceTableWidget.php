<?php

namespace App\Filament\Widgets;

use App\Filament\Exports\CustomersPerformanceTableExporter;
use App\Services\CustomersPerformanceReportService;
use App\Services\FeatureService;
use App\Services\Reports\CustomerReportThresholds;
use Carbon\Carbon;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class CustomersPerformanceTableWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected static ?string $heading = 'تفاصيل أداء العملاء';

    public function table(Table $table): Table
    {
        [$defaultStart, $defaultEnd] = CustomersPerformanceReportService::defaultPeriod();
        $startDate = $this->pageFilters['startDate'] ?? $defaultStart;
        $endDate = $this->pageFilters['endDate'] ?? $defaultEnd;
        $service = app(CustomersPerformanceReportService::class);
        $segments = array_keys(CustomerReportThresholds::segmentColors());

        return $table
            ->query($service->getCustomersPerformanceQuery($startDate, $endDate))
            ->description(app(FeatureService::class)->returnsEnabled() ? 'المبالغ صافية بعد الخصومات والمرتجعات' : 'المبالغ صافية بعد الخصومات')
            ->headerActions([
                ExportAction::make()
                    ->label('تصدير تقرير أداء العملاء')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->exporter(CustomersPerformanceTableExporter::class)
                    ->fileName(fn () => 'customers-performance-'.now()->format('Y-m-d-H-i-s').'.xlsx'),
            ])
            ->columns([
                TextColumn::make('name')
                    ->label('اسم العميل')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('customers.name', 'like', "%{$search}%"))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('customers.name', $direction)),

                TextColumn::make('phone')
                    ->label('رقم الهاتف')
                    ->copyable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('customers.phone', 'like', "%{$search}%")),

                TextColumn::make('region')
                    ->label('المنطقة')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('customers.region', $direction))
                    ->default('غير محدد')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('customer_segment')
                    ->label('التصنيف')
                    ->sortable()
                    ->badge()
                    ->color(fn (string $state): string => CustomerReportThresholds::segmentColors()[$state] ?? 'gray'),

                TextColumn::make('total_orders')
                    ->label('عدد الطلبات')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('total_sales')
                    ->label('صافي المبيعات')
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('total_profit')
                    ->label('صافي الربح')
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('profit_margin')
                    ->label('هامش الربح')
                    ->state(fn ($record) => $record->total_sales > 0
                        ? number_format(($record->total_profit / $record->total_sales) * 100, 1).'%'
                        : '0%'),

                TextColumn::make('avg_order_value')
                    ->label('متوسط قيمة الطلب')
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('total_discount')
                    ->label('الخصومات')
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('total_refunds')
                    ->label('المرتجعات')
                    ->money('EGP')
                    ->sortable()
                    ->visible(fn (): bool => app(FeatureService::class)->returnsEnabled()),

                TextColumn::make('avg_days_between_orders')
                    ->label('متوسط الأيام بين الطلبات')
                    ->state(fn ($record) => self::avgDaysBetweenOrders($record))
                    ->placeholder('—'),

                TextColumn::make('total_quantity')
                    ->label('إجمالي الكمية')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('last_order_date')
                    ->label('آخر طلب')
                    ->date('Y-m-d')
                    ->sortable(),

                TextColumn::make('first_order_date')
                    ->label('أول طلب في الفترة')
                    ->date('Y-m-d')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                ...array_map(fn (array $type) => TextColumn::make('type_'.$type['code'].'_sales')
                    ->label($type['label'])->money('EGP')->sortable()->toggleable(),
                    $service->getReportOrderTypes($startDate, $endDate)),
            ])
            ->filters([
                SelectFilter::make('customer_segment')
                    ->label('التصنيف')
                    ->options(array_combine($segments, $segments))
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $segment): Builder => $query->having('customer_segment', '=', $segment),
                    )),
            ])
            ->defaultSort('total_sales', 'desc')
            ->paginated([10, 25, 50, 100])
            ->striped()
            ->recordActions([
                ViewAction::make()
                    ->label('عرض العميل')
                    ->icon('heroicon-o-eye')
                    ->url(fn ($record): string => route('filament.admin.resources.customers.view', $record->id))
                    ->openUrlInNewTab(),
            ])
            ->recordUrl(fn ($record): string => route('filament.admin.resources.customers.view', $record->id));
    }

    public static function avgDaysBetweenOrders($record): ?string
    {
        if ($record->total_orders < 2 || ! $record->first_order_date || ! $record->last_order_date) {
            return null;
        }

        $days = Carbon::parse($record->first_order_date)->diffInDays(Carbon::parse($record->last_order_date));

        return number_format($days / ($record->total_orders - 1), 1, '.', '');
    }
}
