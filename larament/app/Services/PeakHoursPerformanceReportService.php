<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PeakHoursPerformanceReportService
{
    /** @var array<string, Collection<int, object>> */
    private array $hourlyCache = [];

    /** @var array<string, Collection<int, object>> */
    private array $dailyCache = [];

    /**
     * Get database connection driver name.
     */
    private function getDriver(): string
    {
        return DB::connection()->getDriverName();
    }

    private function getHourExpression(): string
    {
        return $this->getDriver() === 'sqlite'
            ? "CAST(strftime('%H', orders.created_at) AS INTEGER)"
            : 'HOUR(orders.created_at)';
    }

    private function getDayOfWeekExpression(): string
    {
        return $this->getDriver() === 'sqlite'
            ? "(CAST(strftime('%w', orders.created_at) AS INTEGER) + 1)"
            : 'DAYOFWEEK(orders.created_at)';
    }

    private function getYearExpression(): string
    {
        return $this->getDriver() === 'sqlite'
            ? "CAST(strftime('%Y', orders.created_at) AS INTEGER)"
            : 'YEAR(orders.created_at)';
    }

    private function getMonthExpression(): string
    {
        return $this->getDriver() === 'sqlite'
            ? "CAST(strftime('%m', orders.created_at) AS INTEGER)"
            : 'MONTH(orders.created_at)';
    }

    private function getWeekExpression(): string
    {
        return $this->getDriver() === 'sqlite'
            ? "CAST(strftime('%W', orders.created_at) AS INTEGER)"
            : 'WEEK(orders.created_at)';
    }

    /**
     * @return Builder<Order>
     */
    public function getOrdersQuery(?string $startDate = null, ?string $endDate = null): Builder
    {
        return Order::query()
            ->where('status', OrderStatus::COMPLETED)
            ->when($startDate, function (Builder $query) use ($startDate) {
                $query->where('orders.created_at', '>=', Carbon::parse($startDate)->startOfDay());
            })
            ->when($endDate, function (Builder $query) use ($endDate) {
                $query->where('orders.created_at', '<=', Carbon::parse($endDate)->endOfDay());
            });
    }

    /**
     * Get hourly performance analysis
     *
     * @return Collection<int, object>
     */
    public function getHourlyPerformance(?string $startDate = null, ?string $endDate = null): Collection
    {
        $cacheKey = ($startDate ?? 'null').'|'.($endDate ?? 'null');
        if (isset($this->hourlyCache[$cacheKey])) {
            return $this->hourlyCache[$cacheKey];
        }

        $hourExpr = $this->getHourExpression();

        /** @var Collection<int, object> $results */
        $results = $this->getOrdersQuery($startDate, $endDate)
            ->select([
                DB::raw("{$hourExpr} as hour"),
                DB::raw('COUNT(orders.id) as total_orders'),
                DB::raw('COALESCE(SUM(orders.total), 0) as total_sales'),
                DB::raw('COALESCE(SUM(orders.profit), 0) as total_profit'),
                DB::raw('COUNT(DISTINCT orders.customer_id) as unique_customers'),
            ])
            ->groupBy(DB::raw($hourExpr))
            ->orderBy('hour')
            ->get()
            ->map(function ($item) {
                $hour = (int) data_get($item, 'hour', 0);
                $totalOrders = (int) data_get($item, 'total_orders', 0);
                $totalSales = (float) data_get($item, 'total_sales', 0.0);
                $totalProfit = (float) data_get($item, 'total_profit', 0.0);
                $avgOrderValue = $totalOrders > 0 ? round($totalSales / $totalOrders, 2) : 0.0;
                $profitMargin = $totalSales > 0 ? round(($totalProfit / $totalSales) * 100, 1) : 0.0;
                $uniqueCustomers = (int) data_get($item, 'unique_customers', 0);

                return (object) [
                    'hour' => $hour,
                    'hour_label' => sprintf('%02d:00', $hour),
                    'total_orders' => $totalOrders,
                    'total_sales' => $totalSales,
                    'total_profit' => $totalProfit,
                    'total_quantity' => 0,
                    'avg_order_value' => $avgOrderValue,
                    'profit_margin' => $profitMargin,
                    'unique_customers' => $uniqueCustomers,
                    'period' => match (true) {
                        $hour >= 6 && $hour < 12 => 'صباح',
                        $hour >= 12 && $hour < 17 => 'ظهر',
                        $hour >= 17 && $hour < 21 => 'مساء',
                        default => 'ليل',
                    },
                ];
            });

        $this->hourlyCache[$cacheKey] = $results;

        return $results;
    }

    /**
     * Get daily performance analysis (grouped by day of week)
     *
     * @return Collection<int, object>
     */
    public function getDailyPerformance(?string $startDate = null, ?string $endDate = null): Collection
    {
        $cacheKey = ($startDate ?? 'null').'|'.($endDate ?? 'null');
        if (isset($this->dailyCache[$cacheKey])) {
            return $this->dailyCache[$cacheKey];
        }

        $dayOfWeekExpr = $this->getDayOfWeekExpression();

        /** @var Collection<int, object> $results */
        $results = $this->getOrdersQuery($startDate, $endDate)
            ->select([
                DB::raw("{$dayOfWeekExpr} as day_of_week"),
                DB::raw('COUNT(orders.id) as total_orders'),
                DB::raw('COALESCE(SUM(orders.total), 0) as total_sales'),
                DB::raw('COALESCE(SUM(orders.profit), 0) as total_profit'),
                DB::raw('COUNT(DISTINCT orders.customer_id) as unique_customers'),
            ])
            ->groupBy(DB::raw($dayOfWeekExpr))
            ->orderBy('day_of_week')
            ->get()
            ->map(function ($item) {
                $dayOfWeek = (int) data_get($item, 'day_of_week', 0);
                $totalOrders = (int) data_get($item, 'total_orders', 0);
                $totalSales = (float) data_get($item, 'total_sales', 0.0);
                $totalProfit = (float) data_get($item, 'total_profit', 0.0);
                $avgOrderValue = $totalOrders > 0 ? round($totalSales / $totalOrders, 2) : 0.0;
                $profitMargin = $totalSales > 0 ? round(($totalProfit / $totalSales) * 100, 1) : 0.0;
                $uniqueCustomers = (int) data_get($item, 'unique_customers', 0);

                $dayLabel = match ($dayOfWeek) {
                    7 => 'السبت',
                    1 => 'الأحد',
                    2 => 'الاثنين',
                    3 => 'الثلاثاء',
                    4 => 'الأربعاء',
                    5 => 'الخميس',
                    6 => 'الجمعة',
                    default => 'غير معروف',
                };

                return (object) [
                    'day_of_week' => $dayOfWeek,
                    'day_label' => $dayLabel,
                    'total_orders' => $totalOrders,
                    'total_sales' => $totalSales,
                    'total_profit' => $totalProfit,
                    'total_quantity' => 0,
                    'avg_order_value' => $avgOrderValue,
                    'profit_margin' => $profitMargin,
                    'unique_customers' => $uniqueCustomers,
                ];
            });

        $this->dailyCache[$cacheKey] = $results;

        return $results;
    }

    /**
     * Get weekly performance analysis
     *
     * @return Collection<int, object>
     */
    public function getWeeklyPerformance(?string $startDate = null, ?string $endDate = null): Collection
    {
        $yearExpr = $this->getYearExpression();
        $weekExpr = $this->getWeekExpression();

        /** @var Collection<int, object> $results */
        $results = $this->getOrdersQuery($startDate, $endDate)
            ->select([
                DB::raw("{$yearExpr} as year"),
                DB::raw("{$weekExpr} as week"),
                DB::raw('MIN(DATE(orders.created_at)) as week_start'),
                DB::raw('MAX(DATE(orders.created_at)) as week_end'),
                DB::raw('COUNT(orders.id) as total_orders'),
                DB::raw('COALESCE(SUM(orders.total), 0) as total_sales'),
                DB::raw('COALESCE(SUM(orders.profit), 0) as total_profit'),
                DB::raw('COUNT(DISTINCT orders.customer_id) as unique_customers'),
                DB::raw('COUNT(DISTINCT DATE(orders.created_at)) as active_days'),
            ])
            ->groupBy(DB::raw($yearExpr), DB::raw($weekExpr))
            ->orderBy('year', 'desc')
            ->orderBy('week', 'desc')
            ->get()
            ->map(function ($item) {
                $year = (int) data_get($item, 'year', 0);
                $week = (int) data_get($item, 'week', 0);
                $totalOrders = (int) data_get($item, 'total_orders', 0);
                $totalSales = (float) data_get($item, 'total_sales', 0.0);
                $totalProfit = (float) data_get($item, 'total_profit', 0.0);
                $avgOrderValue = $totalOrders > 0 ? round($totalSales / $totalOrders, 2) : 0.0;
                $uniqueCustomers = (int) data_get($item, 'unique_customers', 0);
                $activeDays = (int) data_get($item, 'active_days', 0);
                $weekStart = (string) data_get($item, 'week_start', '');
                $weekEnd = (string) data_get($item, 'week_end', '');

                return (object) [
                    'year' => $year,
                    'week' => $week,
                    'week_start' => $weekStart,
                    'week_end' => $weekEnd,
                    'total_orders' => $totalOrders,
                    'total_sales' => $totalSales,
                    'total_profit' => $totalProfit,
                    'total_quantity' => 0,
                    'avg_order_value' => $avgOrderValue,
                    'unique_customers' => $uniqueCustomers,
                    'active_days' => $activeDays,
                    'week_label' => sprintf('أسبوع %d من %d', $week, $year),
                    'date_range' => sprintf(
                        '%s - %s',
                        $weekStart !== '' ? Carbon::parse($weekStart)->format('d/m') : '',
                        $weekEnd !== '' ? Carbon::parse($weekEnd)->format('d/m') : ''
                    ),
                ];
            });

        return $results;
    }

    /**
     * Get monthly performance analysis
     *
     * @return Collection<int, object>
     */
    public function getMonthlyPerformance(?string $startDate = null, ?string $endDate = null): Collection
    {
        $yearExpr = $this->getYearExpression();
        $monthExpr = $this->getMonthExpression();

        /** @var Collection<int, object> $results */
        $results = $this->getOrdersQuery($startDate, $endDate)
            ->select([
                DB::raw("{$yearExpr} as year"),
                DB::raw("{$monthExpr} as month"),
                DB::raw('COUNT(orders.id) as total_orders'),
                DB::raw('COALESCE(SUM(orders.total), 0) as total_sales'),
                DB::raw('COALESCE(SUM(orders.profit), 0) as total_profit'),
                DB::raw('COUNT(DISTINCT orders.customer_id) as unique_customers'),
                DB::raw('COUNT(DISTINCT DATE(orders.created_at)) as active_days'),
            ])
            ->groupBy(DB::raw($yearExpr), DB::raw($monthExpr))
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get()
            ->map(function ($item) {
                $month = (int) data_get($item, 'month', 0);
                $year = (int) data_get($item, 'year', 0);
                $totalOrders = (int) data_get($item, 'total_orders', 0);
                $totalSales = (float) data_get($item, 'total_sales', 0.0);
                $totalProfit = (float) data_get($item, 'total_profit', 0.0);
                $avgOrderValue = $totalOrders > 0 ? round($totalSales / $totalOrders, 2) : 0.0;
                $uniqueCustomers = (int) data_get($item, 'unique_customers', 0);
                $activeDays = (int) data_get($item, 'active_days', 0);

                $monthNames = [
                    1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
                    5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
                    9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
                ];

                return (object) [
                    'year' => $year,
                    'month' => $month,
                    'total_orders' => $totalOrders,
                    'total_sales' => $totalSales,
                    'total_profit' => $totalProfit,
                    'total_quantity' => 0,
                    'avg_order_value' => $avgOrderValue,
                    'unique_customers' => $uniqueCustomers,
                    'active_days' => $activeDays,
                    'month_label' => sprintf('%s %d', $monthNames[$month] ?? (string) $month, $year),
                ];
            });

        return $results;
    }

    /**
     * Get peak performance analysis by different time periods
     *
     * @return array<string, mixed>
     */
    public function getPeakAnalysis(?string $startDate = null, ?string $endDate = null): array
    {
        $hourlyData = $this->getHourlyPerformance($startDate, $endDate);
        $dailyData = $this->getDailyPerformance($startDate, $endDate);

        // Find peak hours
        $peakHour = $hourlyData->where('total_sales', '>', 0)->sortByDesc('total_sales')->first();
        $peakOrdersHour = $hourlyData->where('total_orders', '>', 0)->sortByDesc('total_orders')->first();

        // Find peak days
        $peakDay = $dailyData->where('total_sales', '>', 0)->sortByDesc('total_sales')->first();
        $peakOrdersDay = $dailyData->where('total_orders', '>', 0)->sortByDesc('total_orders')->first();

        // Calculate period performance
        $periodPerformance = [
            'صباح' => (float) $hourlyData->where('period', 'صباح')->sum('total_sales'),
            'ظهر' => (float) $hourlyData->where('period', 'ظهر')->sum('total_sales'),
            'مساء' => (float) $hourlyData->where('period', 'مساء')->sum('total_sales'),
            'ليل' => (float) $hourlyData->where('period', 'ليل')->sum('total_sales'),
        ];

        $bestPeriod = collect($periodPerformance)
            ->filter(fn ($sales) => $sales > 0)
            ->sortDesc()
            ->keys()
            ->first();

        $totalSales = (float) $hourlyData->sum('total_sales');
        $totalOrders = (int) $hourlyData->sum('total_orders');

        // Calculate accurate hourly averages across the period
        $daysCount = 1;
        if ($startDate && $endDate) {
            $daysCount = max(1, (int) Carbon::parse($startDate)->startOfDay()->diffInDays(Carbon::parse($endDate)->endOfDay()) + 1);
        }

        $totalHoursInPeriod = $daysCount * 24;
        $averageHourlySales = round($totalSales / $totalHoursInPeriod, 2);
        $averageHourlyOrders = round($totalOrders / $totalHoursInPeriod, 1);

        return [
            'peak_sales_hour' => $peakHour,
            'peak_orders_hour' => $peakOrdersHour,
            'peak_sales_day' => $peakDay,
            'peak_orders_day' => $peakOrdersDay,
            'period_performance' => $periodPerformance,
            'best_period' => $bestPeriod,
            'total_sales' => $totalSales,
            'total_orders' => $totalOrders,
            'average_hourly_sales' => $averageHourlySales,
            'average_hourly_orders' => $averageHourlyOrders,
        ];
    }

    /**
     * Get performance by order type during different hours
     *
     * @return Collection<int, object>
     */
    public function getOrderTypeHourlyPerformance(?string $startDate = null, ?string $endDate = null): Collection
    {
        $hourExpr = $this->getHourExpression();

        /** @var Collection<int, object> $results */
        $results = $this->getOrdersQuery($startDate, $endDate)
            ->select([
                DB::raw("{$hourExpr} as hour"),
                'orders.type',
                DB::raw('COUNT(orders.id) as total_orders'),
                DB::raw('COALESCE(SUM(orders.total), 0) as total_sales'),
                DB::raw('COALESCE(SUM(orders.profit), 0) as total_profit'),
                DB::raw('COUNT(DISTINCT orders.customer_id) as unique_customers'),
            ])
            ->groupBy(DB::raw($hourExpr), 'orders.type')
            ->orderBy('hour')
            ->orderBy('orders.type')
            ->get()
            ->map(function ($item) {
                $hour = (int) data_get($item, 'hour', 0);
                $typeRaw = data_get($item, 'type');
                $type = $typeRaw instanceof OrderType ? $typeRaw : (is_string($typeRaw) ? OrderType::tryFrom($typeRaw) : null);
                $typeLabel = $type ? $type->label() : (string) ($typeRaw ?? '');

                return (object) [
                    'hour' => $hour,
                    'hour_label' => sprintf('%02d:00', $hour),
                    'type' => $typeRaw,
                    'type_label' => $typeLabel,
                    'total_orders' => (int) data_get($item, 'total_orders', 0),
                    'total_sales' => (float) data_get($item, 'total_sales', 0.0),
                    'total_profit' => (float) data_get($item, 'total_profit', 0.0),
                    'unique_customers' => (int) data_get($item, 'unique_customers', 0),
                ];
            });

        return $results;
    }

    /**
     * Get customer traffic patterns
     *
     * @return array<string, mixed>
     */
    public function getCustomerTrafficPatterns(?string $startDate = null, ?string $endDate = null): array
    {
        $hourExpr = $this->getHourExpression();

        $hourlyTraffic = $this->getOrdersQuery($startDate, $endDate)
            ->select([
                DB::raw("{$hourExpr} as hour"),
                DB::raw('COUNT(DISTINCT orders.customer_id) as unique_customers'),
                DB::raw('COUNT(orders.id) as total_orders'),
                DB::raw('COALESCE(SUM(orders.total), 0) as total_sales'),
            ])
            ->groupBy(DB::raw($hourExpr))
            ->orderBy('hour')
            ->get();

        $trafficMetrics = $hourlyTraffic->map(function ($item) {
            $hour = (int) data_get($item, 'hour', 0);
            $uniqueCustomers = (int) data_get($item, 'unique_customers', 0);
            $totalOrders = (int) data_get($item, 'total_orders', 0);
            $totalSales = (float) data_get($item, 'total_sales', 0.0);

            $customerDensity = $uniqueCustomers > 0 ? round($totalOrders / $uniqueCustomers, 2) : 0.0;
            $revenuePerCustomer = $uniqueCustomers > 0 ? round($totalSales / $uniqueCustomers, 2) : 0.0;

            return (object) [
                'hour' => $hour,
                'hour_label' => sprintf('%02d:00', $hour),
                'unique_customers' => $uniqueCustomers,
                'total_orders' => $totalOrders,
                'total_sales' => $totalSales,
                'customer_density' => $customerDensity,
                'revenue_per_customer' => $revenuePerCustomer,
            ];
        });

        $peakTrafficHour = $trafficMetrics->sortByDesc('unique_customers')->first();
        $peakDensityHour = $trafficMetrics->sortByDesc('customer_density')->first();

        return [
            'hourly_traffic' => $trafficMetrics,
            'peak_traffic_hour' => $peakTrafficHour,
            'peak_density_hour' => $peakDensityHour,
            'total_unique_customers' => (int) $hourlyTraffic->sum('unique_customers'),
            'average_hourly_customers' => $hourlyTraffic->count() > 0 ? round((float) $hourlyTraffic->avg('unique_customers'), 1) : 0.0,
        ];
    }

    /**
     * Get staff optimization recommendations
     *
     * @return array<string, mixed>
     */
    public function getStaffOptimizationInsights(?string $startDate = null, ?string $endDate = null): array
    {
        $hourlyData = $this->getHourlyPerformance($startDate, $endDate);

        $scoredData = $hourlyData->map(function (object $item) {
            $totalOrders = (int) data_get($item, 'total_orders', 0);
            $totalSales = (float) data_get($item, 'total_sales', 0.0);
            $workloadScore = ($totalOrders * 0.6) + (($totalSales / 100) * 0.4);

            return (object) array_merge((array) $item, [
                'workload_score' => $workloadScore,
            ]);
        });

        $maxWorkload = (float) $scoredData->max('workload_score');
        $categories = $scoredData->map(function (object $item) use ($maxWorkload) {
            $score = (float) data_get($item, 'workload_score', 0.0);
            $percentage = $maxWorkload > 0 ? ($score / $maxWorkload) * 100 : 0;

            $workloadCategory = match (true) {
                $percentage >= 80 => 'ذروة عالية',
                $percentage >= 60 => 'ذروة متوسطة',
                $percentage >= 40 => 'عادي',
                $percentage >= 20 => 'هادئ',
                default => 'هادئ جداً',
            };

            $staffRecommendation = match (true) {
                $percentage >= 80 => 'طاقم كامل + دعم إضافي',
                $percentage >= 60 => 'طاقم كامل',
                $percentage >= 40 => 'طاقم عادي',
                $percentage >= 20 => 'طاقم مخفف',
                default => 'حد أدنى من الطاقم',
            };

            return (object) array_merge((array) $item, [
                'workload_category' => $workloadCategory,
                'staff_recommendation' => $staffRecommendation,
            ]);
        });

        return [
            'hourly_workload' => $categories,
            'peak_hours' => $categories->where('workload_category', 'ذروة عالية')->values(),
            'quiet_hours' => $categories->where('workload_category', 'هادئ جداً')->values(),
            'recommendations' => $this->generateStaffRecommendations($categories),
        ];
    }

    /**
     * Generate staff recommendations based on workload analysis
     *
     * @param  Collection<int, mixed>  $hourlyData
     * @return array<int, string>
     */
    private function generateStaffRecommendations(Collection $hourlyData): array
    {
        $recommendations = [];

        $peakHours = $hourlyData->where('workload_category', 'ذروة عالية');
        $quietHours = $hourlyData->where('workload_category', 'هادئ جداً');

        if ($peakHours->isNotEmpty()) {
            $peakTime = $peakHours->pluck('hour_label')->implode('، ');
            $recommendations[] = "زيادة عدد الموظفين خلال ساعات الذروة: {$peakTime}";
        }

        if ($quietHours->isNotEmpty()) {
            $quietTime = $quietHours->pluck('hour_label')->implode('، ');
            $recommendations[] = "تقليل عدد الموظفين خلال الساعات الهادئة: {$quietTime}";
        }

        $morningOrders = (int) $hourlyData->filter(fn ($item) => (int) data_get($item, 'hour') >= 6 && (int) data_get($item, 'hour') <= 11)->sum('total_orders');
        $eveningOrders = (int) $hourlyData->filter(fn ($item) => (int) data_get($item, 'hour') >= 17 && (int) data_get($item, 'hour') <= 22)->sum('total_orders');

        if ($eveningOrders > $morningOrders * 1.5 && $morningOrders > 0) {
            $recommendations[] = 'التركيز على تقوية الطاقم المسائي - حيث تزيد الطلبات بنسبة 50% عن الصباح';
        }

        return $recommendations;
    }

    /**
     * Get period info for display
     *
     * @return array{title: string, description: string}
     */
    public function getPeriodInfo(?string $startDate = null, ?string $endDate = null): array
    {
        if (! $startDate && ! $endDate) {
            return [
                'title' => 'تقرير أداء ساعات الذروة - جميع الفترات',
                'description' => 'تحليل أنماط المبيعات والذروة عبر الساعات والأيام والأسابيع',
            ];
        }

        $start = $startDate ? Carbon::parse($startDate) : null;
        $end = $endDate ? Carbon::parse($endDate) : null;

        if ($start && $end) {
            return [
                'title' => 'تقرير أداء ساعات الذروة',
                'description' => sprintf(
                    'تحليل أنماط المبيعات والذروة من %s إلى %s',
                    $start->format('d/m/Y'),
                    $end->format('d/m/Y')
                ),
            ];
        } elseif ($start) {
            return [
                'title' => 'تقرير أداء ساعات الذروة',
                'description' => sprintf('تحليل أنماط المبيعات والذروة من %s حتى الآن', $start->format('d/m/Y')),
            ];
        } else {
            return [
                'title' => 'تقرير أداء ساعات الذروة',
                'description' => sprintf('تحليل أنماط المبيعات والذروة حتى %s', $end->format('d/m/Y')),
            ];
        }
    }
}
