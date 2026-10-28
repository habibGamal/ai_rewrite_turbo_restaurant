<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DailyComparisonReportService
{
    /**
     * Get base query for completed orders in the given date range.
     *
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
     * Get performance data formatted for the requested view type:
     * - 'by_date': Daily sequential breakdown
     * - 'by_day_of_week': Aggregated 7 days of the week (Saturday to Friday)
     * - 'by_month': Month-by-month breakdown
     */
    public function getPerformanceData(string $startDate, string $endDate, string $viewType = 'by_date'): Collection
    {
        return match ($viewType) {
            'by_day_of_week' => $this->getDaysOfWeekPerformance($startDate, $endDate),
            'by_month' => $this->getMonthlyPerformance($startDate, $endDate),
            default => $this->getDailyPerformance($startDate, $endDate),
        };
    }

    /**
     * Get daily performance analysis for each date in the period.
     * Missing dates are filled with zero values to maintain timeline continuity.
     */
    public function getDailyPerformance(string $startDate, string $endDate): Collection
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        // Query grouped by date
        $ordersGrouped = $this->getOrdersQuery($startDate, $endDate)
            ->select([
                DB::raw('DATE(orders.created_at) as order_date'),
                DB::raw('COUNT(orders.id) as total_orders'),
                DB::raw('COALESCE(SUM(orders.total), 0) as total_sales'),
                DB::raw('COALESCE(SUM(orders.profit), 0) as total_profit'),
            ])
            ->groupBy(DB::raw('DATE(orders.created_at)'))
            ->get()
            ->keyBy('order_date');

        $result = collect();
        $current = $start->copy();

        while ($current->lte($end)) {
            $dateString = $current->toDateString();
            $existing = $ordersGrouped->get($dateString);

            $ordersCount = (int) data_get($existing, 'total_orders', 0);
            $sales = (float) data_get($existing, 'total_sales', 0.0);
            $profit = (float) data_get($existing, 'total_profit', 0.0);
            $avgOrderValue = $ordersCount > 0 ? round($sales / $ordersCount, 2) : 0.0;
            $profitMargin = $sales > 0 ? round(($profit / $sales) * 100, 1) : 0.0;

            $dayName = $current->locale('ar')->translatedFormat('l');
            $dayLabel = sprintf('%s (%s)', $dayName, $current->format('d/m'));

            $result->push((object) [
                'period_key' => $dateString,
                'date' => $dateString,
                'day_name' => $dayName,
                'day_label' => $dayLabel,
                'day_number' => $current->day,
                'total_orders' => $ordersCount,
                'total_sales' => round($sales, 2),
                'total_profit' => round($profit, 2),
                'profit_margin' => $profitMargin,
                'avg_order_value' => $avgOrderValue,
            ]);

            $current->addDay();
        }

        return $result;
    }

    /**
     * Get aggregate performance for the 7 days of the week, starting from Saturday to Friday.
     */
    public function getDaysOfWeekPerformance(string $startDate, string $endDate): Collection
    {
        // 1. Get raw daily data across the period
        $dailyData = $this->getDailyPerformance($startDate, $endDate);

        // 2. Define the 7 days strictly ordered from Saturday to Friday
        $daysConfig = [
            Carbon::SATURDAY => 'السبت',
            Carbon::SUNDAY => 'الأحد',
            Carbon::MONDAY => 'الاثنين',
            Carbon::TUESDAY => 'الثلاثاء',
            Carbon::WEDNESDAY => 'الأربعاء',
            Carbon::THURSDAY => 'الخميس',
            Carbon::FRIDAY => 'الجمعة',
        ];

        // 3. Aggregate each daily record into its day of the week
        $grouped = $dailyData->groupBy(function ($item) {
            return Carbon::parse($item->date)->dayOfWeek;
        });

        $result = collect();

        foreach ($daysConfig as $dayOfWeek => $dayName) {
            $dayRecords = $grouped->get($dayOfWeek, collect());

            $totalOrders = (int) $dayRecords->sum('total_orders');
            $totalSales = (float) $dayRecords->sum('total_sales');
            $totalProfit = (float) $dayRecords->sum('total_profit');
            $avgOrderValue = $totalOrders > 0 ? round($totalSales / $totalOrders, 2) : 0.0;
            $profitMargin = $totalSales > 0 ? round(($totalProfit / $totalSales) * 100, 1) : 0.0;
            $occurrences = $dayRecords->count();

            $result->push((object) [
                'period_key' => (string) $dayOfWeek,
                'date' => null,
                'day_name' => $dayName,
                'day_label' => $dayName,
                'day_number' => $dayOfWeek,
                'occurrences' => $occurrences,
                'total_orders' => $totalOrders,
                'total_sales' => round($totalSales, 2),
                'total_profit' => round($totalProfit, 2),
                'profit_margin' => $profitMargin,
                'avg_order_value' => $avgOrderValue,
            ]);
        }

        return $result;
    }

    /**
     * Get monthly performance analysis grouped by Month within the period.
     */
    public function getMonthlyPerformance(string $startDate, string $endDate): Collection
    {
        $start = Carbon::parse($startDate)->startOfMonth();
        $end = Carbon::parse($endDate)->endOfMonth();

        $monthNames = [
            1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
            5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
            9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
        ];

        // Get daily data and aggregate by Year-Month
        $dailyData = $this->getDailyPerformance($startDate, $endDate);

        $grouped = $dailyData->groupBy(function ($item) {
            return Carbon::parse($item->date)->format('Y-m');
        });

        $result = collect();
        $current = $start->copy();

        while ($current->lte($end)) {
            $yearMonthKey = $current->format('Y-m');
            $monthRecords = $grouped->get($yearMonthKey, collect());

            $totalOrders = (int) $monthRecords->sum('total_orders');
            $totalSales = (float) $monthRecords->sum('total_sales');
            $totalProfit = (float) $monthRecords->sum('total_profit');
            $avgOrderValue = $totalOrders > 0 ? round($totalSales / $totalOrders, 2) : 0.0;
            $profitMargin = $totalSales > 0 ? round(($totalProfit / $totalSales) * 100, 1) : 0.0;

            $monthLabel = sprintf('%s %d', $monthNames[$current->month], $current->year);

            $result->push((object) [
                'period_key' => $yearMonthKey,
                'date' => $yearMonthKey,
                'day_name' => $monthNames[$current->month],
                'day_label' => $monthLabel,
                'day_number' => $current->month,
                'total_orders' => $totalOrders,
                'total_sales' => round($totalSales, 2),
                'total_profit' => round($totalProfit, 2),
                'profit_margin' => $profitMargin,
                'avg_order_value' => $avgOrderValue,
            ]);

            $current->addMonth();
        }

        return $result;
    }

    /**
     * Calculate summary KPI statistics for the selected period.
     */
    public function getPeriodSummary(string $startDate, string $endDate, string $viewType = 'by_date'): array
    {
        $performanceData = $this->getPerformanceData($startDate, $endDate, $viewType);

        $totalOrders = (int) $performanceData->sum('total_orders');
        $totalSales = (float) $performanceData->sum('total_sales');
        $totalProfit = (float) $performanceData->sum('total_profit');
        $avgOrderValue = $totalOrders > 0 ? round($totalSales / $totalOrders, 2) : 0.0;
        $profitMargin = $totalSales > 0 ? round(($totalProfit / $totalSales) * 100, 1) : 0.0;

        $activePeriods = $performanceData->where('total_orders', '>', 0);
        $activePeriodsCount = $activePeriods->count();
        $totalPeriodsCount = $performanceData->count();

        $bestSalesPeriod = $performanceData->sortByDesc('total_sales')->first();
        $bestOrdersPeriod = $performanceData->sortByDesc('total_orders')->first();
        $lowestSalesPeriod = $activePeriods->sortBy('total_sales')->first() ?: $performanceData->first();

        $avgDailySales = $totalPeriodsCount > 0 ? round($totalSales / $totalPeriodsCount, 2) : 0.0;
        $avgDailyOrders = $totalPeriodsCount > 0 ? round($totalOrders / $totalPeriodsCount, 1) : 0.0;

        return [
            'total_orders' => $totalOrders,
            'total_sales' => $totalSales,
            'total_profit' => $totalProfit,
            'avg_order_value' => $avgOrderValue,
            'profit_margin' => $profitMargin,
            'best_sales_period' => $bestSalesPeriod,
            'best_orders_period' => $bestOrdersPeriod,
            'lowest_sales_period' => $lowestSalesPeriod,
            'avg_daily_sales' => $avgDailySales,
            'avg_daily_orders' => $avgDailyOrders,
            'active_periods_count' => $activePeriodsCount,
            'total_periods_count' => $totalPeriodsCount,
        ];
    }
}
