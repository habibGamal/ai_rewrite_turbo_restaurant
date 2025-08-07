<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Category;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ProductType;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Collection;
use Carbon\Carbon;

class ProductsSalesReportService
{
    public function getProductsSalesPerformanceQuery(?string $startDate = null, ?string $endDate = null)
    {
        // Get orders within date range
        $ordersQuery = Order::query()
            ->where('status', OrderStatus::COMPLETED);

        if ($startDate) {
            $ordersQuery->where('created_at', '>=', Carbon::parse($startDate)->startOfDay());
        }

        if ($endDate) {
            $ordersQuery->where('created_at', '<=', Carbon::parse($endDate)->endOfDay());
        }

        $orderIds = $ordersQuery->pluck('id')->toArray();

        // Get products with sales data aggregated by order type
        return Product::query()
            ->whereNot('products.type', ProductType::RawMaterial)
            ->select([
                'products.id',
                'products.name',
                'products.price',
                'products.cost',
                'products.type',
                'categories.name as category_name',
                // Total sales across all order types
                DB::raw('COALESCE(SUM(order_items.quantity), 0) as total_quantity'),
                DB::raw('COALESCE(SUM(order_items.total), 0) as total_sales'),
                DB::raw('COALESCE(SUM(order_items.total - (order_items.cost * order_items.quantity)), 0) as total_profit'),

                // Dine In
                DB::raw('COALESCE(SUM(CASE WHEN orders.type = "dine_in" THEN order_items.quantity ELSE 0 END), 0) as dine_in_quantity'),
                DB::raw('COALESCE(SUM(CASE WHEN orders.type = "dine_in" THEN order_items.total ELSE 0 END), 0) as dine_in_sales'),
                DB::raw('COALESCE(SUM(CASE WHEN orders.type = "dine_in" THEN order_items.total - (order_items.cost * order_items.quantity) ELSE 0 END), 0) as dine_in_profit'),

                // Takeaway
                DB::raw('COALESCE(SUM(CASE WHEN orders.type = "takeaway" THEN order_items.quantity ELSE 0 END), 0) as takeaway_quantity'),
                DB::raw('COALESCE(SUM(CASE WHEN orders.type = "takeaway" THEN order_items.total ELSE 0 END), 0) as takeaway_sales'),
                DB::raw('COALESCE(SUM(CASE WHEN orders.type = "takeaway" THEN order_items.total - (order_items.cost * order_items.quantity) ELSE 0 END), 0) as takeaway_profit'),

                // Delivery
                DB::raw('COALESCE(SUM(CASE WHEN orders.type = "delivery" THEN order_items.quantity ELSE 0 END), 0) as delivery_quantity'),
                DB::raw('COALESCE(SUM(CASE WHEN orders.type = "delivery" THEN order_items.total ELSE 0 END), 0) as delivery_sales'),
                DB::raw('COALESCE(SUM(CASE WHEN orders.type = "delivery" THEN order_items.total - (order_items.cost * order_items.quantity) ELSE 0 END), 0) as delivery_profit'),

                // Web Delivery
                DB::raw('COALESCE(SUM(CASE WHEN orders.type = "web_delivery" THEN order_items.quantity ELSE 0 END), 0) as web_delivery_quantity'),
                DB::raw('COALESCE(SUM(CASE WHEN orders.type = "web_delivery" THEN order_items.total ELSE 0 END), 0) as web_delivery_sales'),
                DB::raw('COALESCE(SUM(CASE WHEN orders.type = "web_delivery" THEN order_items.total - (order_items.cost * order_items.quantity) ELSE 0 END), 0) as web_delivery_profit'),

                // Web Takeaway
                DB::raw('COALESCE(SUM(CASE WHEN orders.type = "web_takeaway" THEN order_items.quantity ELSE 0 END), 0) as web_takeaway_quantity'),
                DB::raw('COALESCE(SUM(CASE WHEN orders.type = "web_takeaway" THEN order_items.total ELSE 0 END), 0) as web_takeaway_sales'),
                DB::raw('COALESCE(SUM(CASE WHEN orders.type = "web_takeaway" THEN order_items.total - (order_items.cost * order_items.quantity) ELSE 0 END), 0) as web_takeaway_profit'),

                // Talabat
                DB::raw('COALESCE(SUM(CASE WHEN orders.type = "talabat" THEN order_items.quantity ELSE 0 END), 0) as talabat_quantity'),
                DB::raw('COALESCE(SUM(CASE WHEN orders.type = "talabat" THEN order_items.total ELSE 0 END), 0) as talabat_sales'),
                DB::raw('COALESCE(SUM(CASE WHEN orders.type = "talabat" THEN order_items.total - (order_items.cost * order_items.quantity) ELSE 0 END), 0) as talabat_profit'),

                // Companies
                DB::raw('COALESCE(SUM(CASE WHEN orders.type = "companies" THEN order_items.quantity ELSE 0 END), 0) as companies_quantity'),
                DB::raw('COALESCE(SUM(CASE WHEN orders.type = "companies" THEN order_items.total ELSE 0 END), 0) as companies_sales'),
                DB::raw('COALESCE(SUM(CASE WHEN orders.type = "companies" THEN order_items.total - (order_items.cost * order_items.quantity) ELSE 0 END), 0) as companies_profit'),
            ])
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->leftJoin('order_items', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('orders', function ($join) use ($orderIds) {
                $join->on('order_items.order_id', '=', 'orders.id')
                    ->whereIn('orders.id', $orderIds);
            })
            ->groupBy('products.id', 'products.name', 'products.price', 'products.cost', 'products.type', 'categories.name')
            ->havingRaw('total_quantity > 0');
    }
    /**
     * Get products sales performance within a date range
     */
    public function getProductsSalesPerformance(?string $startDate = null, ?string $endDate = null): Collection
    {
        return $this->getProductsSalesPerformanceQuery($startDate, $endDate)->get();
    }

    /**
     * Get top performing products by sales value
     */
    public function getTopProductsBySales(?string $startDate = null, ?string $endDate = null, int $limit = 10): Collection
    {
        return $this->getProductsSalesPerformance($startDate, $endDate)
            ->sortByDesc('total_sales')
            ->take($limit);
    }

    /**
     * Get top performing products by profit
     */
    public function getTopProductsByProfit(?string $startDate = null, ?string $endDate = null, int $limit = 10): Collection
    {
        return $this->getProductsSalesPerformance($startDate, $endDate)
            ->sortByDesc('total_profit')
            ->take($limit);
    }

    /**
     * Get top performing products by quantity sold
     */
    public function getTopProductsByQuantity(?string $startDate = null, ?string $endDate = null, int $limit = 10): Collection
    {
        return $this->getProductsSalesPerformance($startDate, $endDate)
            ->sortByDesc('total_quantity')
            ->take($limit);
    }

    /**
     * Get period statistics summary
     */
    public function getPeriodSummary(?string $startDate = null, ?string $endDate = null): array
    {
        $products = $this->getProductsSalesPerformance($startDate, $endDate);

        if ($products->isEmpty()) {
            return [
                'total_products' => 0,
                'total_sales' => 0,
                'total_profit' => 0,
                'total_quantity' => 0,
                'avg_profit_margin' => 0,
                'best_selling_product' => null,
                'most_profitable_product' => null,
            ];
        }

        $totalSales = $products->sum('total_sales');
        $totalProfit = $products->sum('total_profit');
        $totalQuantity = $products->sum('total_quantity');
        $avgProfitMargin = $totalSales > 0 ? ($totalProfit / $totalSales) * 100 : 0;

        $bestSellingProduct = $products->sortByDesc('total_sales')->first();
        $mostProfitableProduct = $products->sortByDesc('total_profit')->first();

        return [
            'total_products' => $products->count(),
            'total_sales' => $totalSales,
            'total_profit' => $totalProfit,
            'total_quantity' => $totalQuantity,
            'avg_profit_margin' => $avgProfitMargin,
            'best_selling_product' => $bestSellingProduct,
            'most_profitable_product' => $mostProfitableProduct,
        ];
    }

    /**
     * Get order type performance summary
     */
    public function getOrderTypePerformance(?string $startDate = null, ?string $endDate = null): array
    {
        $products = $this->getProductsSalesPerformance($startDate, $endDate);

        if ($products->isEmpty()) {
            return [];
        }

        $orderTypes = [
            'dine_in' => 'صالة',
            'takeaway' => 'تيك أواي',
            'delivery' => 'دليفري',
            'web_delivery' => 'اونلاين دليفري',
            'web_takeaway' => 'اونلاين تيك أواي',
            'talabat' => 'طلبات',
            'companies' => 'شركات',
        ];

        $performance = [];

        foreach ($orderTypes as $type => $label) {
            $salesColumn = $type . '_sales';
            $profitColumn = $type . '_profit';
            $quantityColumn = $type . '_quantity';

            $totalSales = $products->sum($salesColumn);
            $totalProfit = $products->sum($profitColumn);
            $totalQuantity = $products->sum($quantityColumn);
            $profitMargin = $totalSales > 0 ? ($totalProfit / $totalSales) * 100 : 0;

            $performance[$type] = [
                'label' => $label,
                'total_sales' => $totalSales,
                'total_profit' => $totalProfit,
                'total_quantity' => $totalQuantity,
                'profit_margin' => $profitMargin,
                'products_count' => $products->filter(function ($product) use ($quantityColumn) {
                    return $product->$quantityColumn > 0;
                })->count(),
            ];
        }

        // Sort by total sales descending
        uasort($performance, function ($a, $b) {
            return $b['total_sales'] <=> $a['total_sales'];
        });

        return $performance;
    }

    /**
     * Get category performance summary
     */
    public function getCategoryPerformance(?string $startDate = null, ?string $endDate = null)
    {
        // Get orders within date range
        $ordersQuery = Order::query()
            ->where('status', OrderStatus::COMPLETED);

        if ($startDate) {
            $ordersQuery->where('created_at', '>=', Carbon::parse($startDate)->startOfDay());
        }

        if ($endDate) {
            $ordersQuery->where('created_at', '<=', Carbon::parse($endDate)->endOfDay());
        }

        $orderIds = $ordersQuery->pluck('id')->toArray();


        // Get category performance aggregated directly at the database level
        return Category::query()
            ->select([
                DB::raw('categories.id as id'),
                DB::raw('COALESCE(categories.name, "غير مصنف") as category_name'),
                DB::raw('COUNT(DISTINCT products.id) as products_count'),
                DB::raw('COALESCE(SUM(order_items.quantity), 0) as total_quantity'),
                DB::raw('COALESCE(SUM(order_items.total), 0) as total_sales'),
                DB::raw('COALESCE(SUM(order_items.total - (order_items.cost * order_items.quantity)), 0) as total_profit'),
            ])
            ->leftJoin('products', 'categories.id', '=', 'products.category_id')
            ->leftJoin('order_items', function ($join) {
                $join->on('products.id', '=', 'order_items.product_id')
                    ->whereNot('products.type', ProductType::RawMaterial);
            })
            ->leftJoin('orders', function ($join) use ($orderIds) {
                $join->on('order_items.order_id', '=', 'orders.id')
                    ->whereIn('orders.id', $orderIds);
            })
            ->groupBy('categories.id', 'categories.name')
            ->havingRaw('total_sales > 0');
    }

    /**
     * Get period info for display
     */
    public function getPeriodInfo(?string $startDate = null, ?string $endDate = null): array
    {
        if (!$startDate && !$endDate) {
            return [
                'title' => 'تقرير أداء المنتجات - جميع الفترات',
                'description' => 'أداء المبيعات والأرباح لجميع المنتجات عبر جميع أنواع الطلبات',
            ];
        }

        $start = $startDate ? Carbon::parse($startDate) : null;
        $end = $endDate ? Carbon::parse($endDate) : null;

        if ($start && $end) {
            return [
                'title' => 'تقرير أداء المنتجات',
                'description' => sprintf(
                    'أداء المبيعات والأرباح من %s إلى %s',
                    $start->format('d/m/Y'),
                    $end->format('d/m/Y')
                ),
            ];
        } elseif ($start) {
            return [
                'title' => 'تقرير أداء المنتجات',
                'description' => sprintf('أداء المبيعات والأرباح من %s حتى الآن', $start->format('d/m/Y')),
            ];
        } else {
            return [
                'title' => 'تقرير أداء المنتجات',
                'description' => sprintf('أداء المبيعات والأرباح حتى %s', $end->format('d/m/Y')),
            ];
        }
    }
}
