<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Reports\ReportOrderTypesService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CustomersPerformanceReportService
{
    public function getOrdersQuery(?string $startDate = null, ?string $endDate = null)
    {
        return Order::query()
            ->where('status', OrderStatus::COMPLETED)
            ->whereNotNull('orders.customer_id')
            ->when($startDate, function ($query) use ($startDate) {
                $query->where('orders.created_at', '>=', Carbon::parse($startDate)->startOfDay());
            })
            ->when($endDate, function ($query) use ($endDate) {
                $query->where('orders.created_at', '<=', Carbon::parse($endDate)->endOfDay());
            });
    }

    public function getCustomersPerformanceQuery(?string $startDate = null, ?string $endDate = null)
    {
        // Get customers with sales data aggregated by order type
        $query = Customer::query()
            ->select([
                'customers.id',
                'customers.name',
                'customers.phone',
                'customers.region',
                'customers.delivery_cost',
                // Total sales across all order types
                DB::raw('COALESCE(COUNT(DISTINCT orders.id), 0) as total_orders'),
                DB::raw('COALESCE(SUM(order_items.quantity), 0) as total_quantity'),
                DB::raw('COALESCE(SUM(order_items.total), 0) as total_sales'),
                DB::raw('COALESCE(SUM(order_items.total - (order_items.cost * order_items.quantity)), 0) as total_profit'),
                DB::raw('COALESCE(CASE WHEN COUNT(DISTINCT orders.id) > 0 THEN SUM(order_items.total) / COUNT(DISTINCT orders.id) ELSE 0 END, 0) as avg_order_value'),
                DB::raw('MAX(orders.created_at) as last_order_date'),
                DB::raw('MIN(orders.created_at) as first_order_date'),

                // Customer Segment
                DB::raw('CASE
                    WHEN COALESCE(SUM(order_items.total), 0) >= 5000 AND COALESCE(COUNT(DISTINCT orders.id), 0) >= 20 THEN "VIP"
                    WHEN COALESCE(SUM(order_items.total), 0) >= 2000 AND COALESCE(COUNT(DISTINCT orders.id), 0) >= 10 THEN "مخلص"
                    WHEN COALESCE(COUNT(DISTINCT orders.id), 0) >= 5 THEN "عادي"
                    ELSE "جديد"
                END as customer_segment'),
            ])
            ->leftJoin('orders', function ($join) use ($startDate, $endDate) {
                $join->on('customers.id', '=', 'orders.customer_id')
                    ->where('orders.status', OrderStatus::COMPLETED)
                    ->when($startDate, function ($query) use ($startDate) {
                        $query->where('orders.created_at', '>=', Carbon::parse($startDate)->startOfDay());
                    })
                    ->when($endDate, function ($query) use ($endDate) {
                        $query->where('orders.created_at', '<=', Carbon::parse($endDate)->endOfDay());
                    });
            })
            ->leftJoin('order_items', 'orders.id', '=', 'order_items.order_id')
            ->groupBy(
                'customers.id',
                'customers.name',
                'customers.phone',
                'customers.region',
                'customers.delivery_cost'
            );

        foreach ($this->getReportOrderTypes($startDate, $endDate) as $type) {
            $code = $type['code'];
            foreach (['quantity' => 'order_items.quantity', 'sales' => 'order_items.total', 'profit' => 'order_items.total - (order_items.cost * order_items.quantity)'] as $metric => $expression) {
                $query->selectRaw("COALESCE(SUM(CASE WHEN orders.type = ? THEN {$expression} ELSE 0 END), 0) as type_{$code}_{$metric}", [$code]);
            }
            $query->selectRaw("COUNT(DISTINCT CASE WHEN orders.type = ? THEN orders.id END) as type_{$code}_orders", [$code]);
        }

        return $query;
    }

    public function getReportOrderTypes(?string $startDate = null, ?string $endDate = null): array
    {
        return app(ReportOrderTypesService::class)->forQuery($this->getOrdersQuery($startDate, $endDate));
    }

    /**
     * Get customers performance within a date range
     */
    public function getCustomersPerformance(?string $startDate = null, ?string $endDate = null): Collection
    {
        return $this->getCustomersPerformanceQuery($startDate, $endDate)->get();
    }

    /**
     * Get period statistics summary
     */
    public function getPeriodSummary(?string $startDate = null, ?string $endDate = null): array
    {
        $summary = $this->getOrdersQuery($startDate, $endDate)
            ->select([
                DB::raw('COUNT(DISTINCT customers.id) as total_customers'),
                DB::raw('COUNT(DISTINCT orders.id) as total_orders'),
                DB::raw('SUM(order_items.total) as total_sales'),
                DB::raw('SUM(order_items.total - (order_items.cost * order_items.quantity)) as total_profit'),
                DB::raw('SUM(order_items.quantity) as total_quantity'),
                DB::raw('CASE WHEN COUNT(DISTINCT orders.id) > 0 THEN SUM(order_items.total) / COUNT(DISTINCT orders.id) ELSE 0 END as avg_order_value'),
                DB::raw('CASE WHEN COUNT(DISTINCT customers.id) > 0 THEN COUNT(DISTINCT orders.id) / COUNT(DISTINCT customers.id) ELSE 0 END as avg_orders_per_customer'),
            ])
            ->join('customers', 'orders.customer_id', '=', 'customers.id')
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->first();

        // Get top customer by sales
        $topCustomerBySales = $this->getCustomersPerformanceQuery($startDate, $endDate)
            ->orderByDesc('total_sales')
            ->limit(1)
            ->first();

        // Get top customer by profit
        $topCustomerByProfit = $this->getCustomersPerformanceQuery($startDate, $endDate)
            ->orderByDesc('total_profit')
            ->limit(1)
            ->first();

        // Get most frequent customer (by order count)
        $mostFrequentCustomer = $this->getCustomersPerformanceQuery($startDate, $endDate)
            ->orderByDesc('total_orders')
            ->limit(1)
            ->first();

        // Get customer with highest average order value
        $highestAvgOrderCustomer = $this->getCustomersPerformanceQuery($startDate, $endDate)
            ->orderByDesc('avg_order_value')
            ->limit(1)
            ->first();

        if (! $summary) {
            return [
                'total_customers' => 0,
                'total_orders' => 0,
                'total_sales' => 0,
                'total_profit' => 0,
                'total_quantity' => 0,
                'avg_order_value' => 0,
                'avg_orders_per_customer' => 0,
                'top_customer_by_sales' => null,
                'top_customer_by_profit' => null,
                'most_frequent_customer' => null,
                'highest_avg_order_customer' => null,
            ];
        }

        return [
            'total_customers' => $summary->total_customers ?? 0,
            'total_orders' => $summary->total_orders ?? 0,
            'total_sales' => $summary->total_sales ?? 0,
            'total_profit' => $summary->total_profit ?? 0,
            'total_quantity' => $summary->total_quantity ?? 0,
            'avg_order_value' => $summary->avg_order_value ?? 0,
            'avg_orders_per_customer' => $summary->avg_orders_per_customer ?? 0,
            'top_customer_by_sales' => $topCustomerBySales,
            'top_customer_by_profit' => $topCustomerByProfit,
            'most_frequent_customer' => $mostFrequentCustomer,
            'highest_avg_order_customer' => $highestAvgOrderCustomer,
        ];
    }

    /**
     * Get order type performance for customers
     */
    public function getOrderTypePerformance(?string $startDate = null, ?string $endDate = null): array
    {
        $performanceData = $this->getOrdersQuery($startDate, $endDate)
            ->select([
                'orders.type',
                DB::raw('COUNT(DISTINCT orders.customer_id) as unique_customers'),
                DB::raw('COUNT(DISTINCT orders.id) as total_orders'),
                DB::raw('SUM(order_items.quantity) as total_quantity'),
                DB::raw('SUM(order_items.total) as total_sales'),
                DB::raw('SUM(order_items.total - (order_items.cost * order_items.quantity)) as total_profit'),
                DB::raw('AVG(order_items.total) as avg_order_value'),
            ])
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->groupBy('orders.type')
            ->get();

        $data = $performanceData->keyBy('type');

        return array_map(function (array $type) use ($data): array {
            $item = $data->get($type['code']);

            return $type + [
                'quantity' => (float) ($item->total_quantity ?? 0),
                'sales' => (float) ($item->total_sales ?? 0),
                'profit' => (float) ($item->total_profit ?? 0),
                'total_quantity' => (float) ($item->total_quantity ?? 0),
                'total_sales' => (float) ($item->total_sales ?? 0),
                'total_profit' => (float) ($item->total_profit ?? 0),
                'total_orders' => (int) ($item->total_orders ?? 0),
                'unique_customers' => (int) ($item->unique_customers ?? 0),
            ];
        }, $this->getReportOrderTypes($startDate, $endDate));
    }

    /**
     * Get customer segments based on their performance
     */
    public function getCustomerSegments(?string $startDate = null, ?string $endDate = null): array
    {
        $customers = $this->getCustomersPerformanceQuery($startDate, $endDate)->get();

        $segments = [
            'vip' => ['count' => 0, 'total_sales' => 0, 'total_profit' => 0],
            'loyal' => ['count' => 0, 'total_sales' => 0, 'total_profit' => 0],
            'regular' => ['count' => 0, 'total_sales' => 0, 'total_profit' => 0],
            'new' => ['count' => 0, 'total_sales' => 0, 'total_profit' => 0],
        ];

        foreach ($customers as $customer) {
            // Segment customers based on their total sales and order frequency
            if ($customer->total_sales >= 5000 && $customer->total_orders >= 20) {
                $segment = 'vip';
            } elseif ($customer->total_sales >= 2000 && $customer->total_orders >= 10) {
                $segment = 'loyal';
            } elseif ($customer->total_orders >= 5) {
                $segment = 'regular';
            } else {
                $segment = 'new';
            }

            $segments[$segment]['count']++;
            $segments[$segment]['total_sales'] += $customer->total_sales;
            $segments[$segment]['total_profit'] += $customer->total_profit;
        }

        return $segments;
    }

    /**
     * Get period info for display
     */
    public function getPeriodInfo(?string $startDate = null, ?string $endDate = null): array
    {
        if (! $startDate && ! $endDate) {
            return [
                'title' => 'تقرير أداء العملاء - جميع الفترات',
                'description' => 'أداء المبيعات والأرباح لجميع العملاء عبر جميع أنواع الطلبات',
            ];
        }

        $start = $startDate ? Carbon::parse($startDate) : null;
        $end = $endDate ? Carbon::parse($endDate) : null;

        if ($start && $end) {
            return [
                'title' => 'تقرير أداء العملاء',
                'description' => sprintf(
                    'أداء المبيعات والأرباح من %s إلى %s',
                    $start->format('d/m/Y'),
                    $end->format('d/m/Y')
                ),
            ];
        } elseif ($start) {
            return [
                'title' => 'تقرير أداء العملاء',
                'description' => sprintf('أداء المبيعات والأرباح من %s حتى الآن', $start->format('d/m/Y')),
            ];
        } else {
            return [
                'title' => 'تقرير أداء العملاء',
                'description' => sprintf('أداء المبيعات والأرباح حتى %s', $end->format('d/m/Y')),
            ];
        }
    }
}
