<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Services\PeakHoursPerformanceReportService;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Collection;

class HourlyPerformanceTableWidget extends BaseWidget
{
    protected static ?string $heading = 'جدول تفاصيل أداء الساعات';

    protected static ?string $description = 'بيانات تفصيلية لمبيعات وطلبات وأرباح كل ساعة على مدار 24 ساعة';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    use InteractsWithPageFilters;

    protected PeakHoursPerformanceReportService $peakHoursReportService;

    public function boot(): void
    {
        $this->peakHoursReportService = app(PeakHoursPerformanceReportService::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Order::query()->whereRaw('1 = 0'))
            ->columns([
                TextColumn::make('hour_label')
                    ->label('الساعة')
                    ->weight('bold')
                    ->color('primary'),

                TextColumn::make('period')
                    ->label('الفترة')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'صباح' => 'info',
                        'ظهر' => 'warning',
                        'مساء' => 'success',
                        default => 'gray',
                    }),

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

                TextColumn::make('unique_customers')
                    ->label('العملاء المسجلين')
                    ->formatStateUsing(fn ($state) => number_format((int) $state).' عميل')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('sales_share')
                    ->label('نسبة المساهمة')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 1).'%')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('badge_label')
                    ->label('مستوى النشاط')
                    ->badge()
                    ->color(fn ($record) => is_array($record) ? ($record['badge_color'] ?? 'gray') : ($record->badge_color ?? 'gray')),
            ])
            ->paginated(false);
    }

    public function getTableRecords(): Collection
    {
        $startDate = $this->pageFilters['startDate'] ?? now()->subDays(29)->startOfDay()->toDateString();
        $endDate = $this->pageFilters['endDate'] ?? now()->endOfDay()->toDateString();

        $hourlyData = $this->peakHoursReportService->getHourlyPerformance($startDate, $endDate);
        $totalPeriodSales = (float) $hourlyData->sum('total_sales');
        $maxSales = (float) $hourlyData->max('total_sales');
        $maxOrders = (int) $hourlyData->max('total_orders');
        $arrayKeyName = ArrayRecord::getKeyName();

        // Ensure all 24 hours are represented
        return collect(range(0, 23))->map(function (int $hour) use ($hourlyData, $totalPeriodSales, $maxSales, $maxOrders, $arrayKeyName) {
            $existing = $hourlyData->firstWhere('hour', $hour);

            $hourLabel = sprintf('%02d:00', $hour);
            $totalOrders = (int) data_get($existing, 'total_orders', 0);
            $totalSales = (float) data_get($existing, 'total_sales', 0.0);
            $totalProfit = (float) data_get($existing, 'total_profit', 0.0);
            $profitMargin = (float) data_get($existing, 'profit_margin', 0.0);
            $avgOrderValue = (float) data_get($existing, 'avg_order_value', 0.0);
            $uniqueCustomers = (int) data_get($existing, 'unique_customers', 0);
            $period = data_get($existing, 'period', match (true) {
                $hour >= 6 && $hour < 12 => 'صباح',
                $hour >= 12 && $hour < 17 => 'ظهر',
                $hour >= 17 && $hour < 21 => 'مساء',
                default => 'ليل',
            });

            $salesShare = $totalPeriodSales > 0 ? round(($totalSales / $totalPeriodSales) * 100, 1) : 0.0;

            if ($totalSales > 0 && $totalSales === $maxSales) {
                $badgeLabel = 'ذروة مبيعات';
                $badgeColor = 'success';
            } elseif ($totalOrders > 0 && $totalOrders === $maxOrders) {
                $badgeLabel = 'أعلى طلبات';
                $badgeColor = 'info';
            } elseif ($totalOrders > 0) {
                $badgeLabel = 'ساعة نشطة';
                $badgeColor = 'primary';
            } else {
                $badgeLabel = 'هادئ';
                $badgeColor = 'gray';
            }

            return [
                $arrayKeyName => (string) $hour,
                'key' => (string) $hour,
                'hour' => $hour,
                'hour_label' => $hourLabel,
                'period' => $period,
                'total_orders' => $totalOrders,
                'total_sales' => $totalSales,
                'total_profit' => $totalProfit,
                'profit_margin' => $profitMargin,
                'avg_order_value' => $avgOrderValue,
                'unique_customers' => $uniqueCustomers,
                'sales_share' => $salesShare,
                'badge_label' => $badgeLabel,
                'badge_color' => $badgeColor,
            ];
        });
    }
}
