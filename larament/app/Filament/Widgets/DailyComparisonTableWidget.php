<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Services\DailyComparisonReportService;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Collection;

class DailyComparisonTableWidget extends BaseWidget
{
    protected static ?string $heading = 'جدول تفاصيل المقارنة اليومية والفترات';

    protected static ?string $description = 'بيانات تفصيلية شاملة للمبيعات والأرباح والطلبات ومتوسط كل يوم أو فترة';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    use InteractsWithPageFilters;

    protected DailyComparisonReportService $reportService;

    public function boot(): void
    {
        $this->reportService = app(DailyComparisonReportService::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Order::query()->whereRaw('1 = 0'))
            ->columns([
                TextColumn::make('day_label')
                    ->label('اليوم / الفترة')
                    ->weight('bold')
                    ->color('primary'),

                TextColumn::make('total_orders')
                    ->label('عدد الطلبات')
                    ->formatStateUsing(fn ($state) => number_format((int) $state).' طلب')
                    ->badge()
                    ->color('info'),

                TextColumn::make('total_sales')
                    ->label('إجمالي المبيعات')
                    ->money('EGP')
                    ->color('success')
                    ->weight('semibold'),

                TextColumn::make('total_profit')
                    ->label('إجمالي الأرباح')
                    ->money('EGP')
                    ->color('warning'),

                TextColumn::make('profit_margin')
                    ->label('هامش الربح')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 1).'%')
                    ->badge()
                    ->color(fn ($state) => (float) $state >= 30 ? 'success' : ((float) $state >= 15 ? 'warning' : 'gray')),

                TextColumn::make('avg_order_value')
                    ->label('متوسط قيمة الطلب')
                    ->money('EGP')
                    ->color('primary'),

                TextColumn::make('sales_share')
                    ->label('نسبة المساهمة')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 1).'%')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('badge_label')
                    ->label('التقييم')
                    ->badge()
                    ->color(fn ($record) => is_array($record) ? ($record['badge_color'] ?? 'gray') : ($record->badge_color ?? 'gray')),
            ])
            ->paginated(false);
    }

    public function getTableRecords(): Collection
    {
        $startDate = $this->pageFilters['startDate'] ?? now()->startOfMonth()->toDateString();
        $endDate = $this->pageFilters['endDate'] ?? now()->endOfMonth()->toDateString();
        $viewType = $this->pageFilters['viewType'] ?? 'by_date';

        $data = $this->reportService->getPerformanceData($startDate, $endDate, $viewType);
        $totalPeriodSales = (float) $data->sum('total_sales');

        $maxSales = (float) $data->max('total_sales');
        $maxOrders = (int) $data->max('total_orders');
        $arrayKeyName = ArrayRecord::getKeyName();

        return $data->map(function ($item) use ($totalPeriodSales, $maxSales, $maxOrders, $arrayKeyName) {
            $salesShare = $totalPeriodSales > 0 ? round(($item->total_sales / $totalPeriodSales) * 100, 1) : 0.0;

            if ($item->total_sales > 0 && $item->total_sales === $maxSales) {
                $badgeLabel = 'أعلى مبيعات';
                $badgeColor = 'success';
            } elseif ($item->total_orders > 0 && $item->total_orders === $maxOrders) {
                $badgeLabel = 'أكثر طلبات';
                $badgeColor = 'info';
            } elseif ($item->total_orders > 0) {
                $badgeLabel = 'يوم نشط';
                $badgeColor = 'primary';
            } else {
                $badgeLabel = 'بدون طلبات';
                $badgeColor = 'gray';
            }

            return [
                $arrayKeyName => (string) $item->period_key,
                'key' => (string) $item->period_key,
                'period_key' => (string) $item->period_key,
                'day_label' => $item->day_label,
                'total_orders' => $item->total_orders,
                'total_sales' => $item->total_sales,
                'total_profit' => $item->total_profit,
                'profit_margin' => $item->profit_margin,
                'avg_order_value' => $item->avg_order_value,
                'sales_share' => $salesShare,
                'badge_label' => $badgeLabel,
                'badge_color' => $badgeColor,
            ];
        });
    }
}
