<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Services\Reports\ReportOrderTypesService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProductsSalesReportService
{
    public function getOrdersQuery(?string $startDate = null, ?string $endDate = null, ?array $shiftIds = null)
    {
        $query = Order::query()
            ->where('status', OrderStatus::COMPLETED);

        if ($shiftIds !== null) {
            if (empty($shiftIds)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereIn('shift_id', $shiftIds);
        }

        return $query
            ->when($startDate, function ($query) use ($startDate) {
                $query->where('created_at', '>=', Carbon::parse($startDate)->startOfDay());
            })
            ->when($endDate, function ($query) use ($endDate) {
                $query->where('created_at', '<=', Carbon::parse($endDate)->endOfDay());
            });
    }

    public function getProductsSalesPerformanceQuery(?string $startDate = null, ?string $endDate = null, ?array $shiftIds = null)
    {
        // Get products with sales data aggregated by order type
        $query = Product::query()
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

            ])
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->leftJoin('order_items', function ($join) {
                $join->on('products.id', '=', 'order_items.product_id');
                // ->whereExists(function ($query) use ($startDate, $endDate) {
                //     $query->select(DB::raw(1))
                //         ->from('orders')
                //         ->whereColumn('orders.id', 'order_items.order_id')
                //         ->whereBetween('orders.created_at', [
                //             $startDate ? Carbon::parse($startDate)->startOfDay() : now()->subDays(30)->startOfDay(),
                //             $endDate ? Carbon::parse($endDate)->endOfDay() : now()->endOfDay()
                //         ]);
                // })
            })
            ->join('orders', function ($join) use ($startDate, $endDate, $shiftIds) {
                $join->on('order_items.order_id', '=', 'orders.id')
                    ->where('orders.status', OrderStatus::COMPLETED);

                if ($shiftIds !== null) {
                    if (empty($shiftIds)) {
                        $join->whereRaw('1 = 0');
                    } else {
                        $join->whereIn('orders.shift_id', $shiftIds);
                    }

                    return;
                }

                $join->whereBetween('orders.created_at', [
                    $startDate ? Carbon::parse($startDate)->startOfDay() : now()->subDays(30)->startOfDay(),
                    $endDate ? Carbon::parse($endDate)->endOfDay() : now()->endOfDay(),
                ]);
            })
            ->groupBy('products.id', 'products.name', 'products.price', 'products.cost', 'products.type', 'categories.name');

        foreach ($this->getReportOrderTypes($startDate, $endDate, $shiftIds) as $type) {
            $code = $type['code'];
            foreach (['quantity' => 'order_items.quantity', 'sales' => 'order_items.total', 'profit' => 'order_items.total - (order_items.cost * order_items.quantity)'] as $metric => $expression) {
                $query->selectRaw("COALESCE(SUM(CASE WHEN orders.type = ? THEN {$expression} ELSE 0 END), 0) as type_{$code}_{$metric}", [$code]);
            }
        }

        return $query;
    }

    public function getReportOrderTypes(?string $startDate = null, ?string $endDate = null, ?array $shiftIds = null): array
    {
        return app(ReportOrderTypesService::class)->forQuery($this->getOrdersQuery($startDate ?? now()->subDays(30)->toDateString(), $endDate ?? now()->toDateString(), $shiftIds));
    }

    /**
     * Get period statistics summary
     */
    public function getPeriodSummary(?string $startDate = null, ?string $endDate = null, ?array $shiftIds = null): array
    {
        $summary = DB::table('products as p')
            ->whereNot('p.type', ProductType::RawMaterial)
            ->select([
                DB::raw('COUNT(DISTINCT order_items.product_id) as total_products'),
                DB::raw('SUM(order_items.total) as total_sales'),
                DB::raw('SUM(order_items.total - (p.cost * order_items.quantity)) as total_profit'),
                DB::raw('SUM(order_items.quantity) as total_quantity'),
                DB::raw('AVG((order_items.total - (p.cost * order_items.quantity)) / order_items.total * 100) as avg_profit_margin'),
                DB::raw('MAX(order_items.total) as best_selling_product_id'),
            ])
            ->join('order_items', 'p.id', '=', 'order_items.product_id')
            ->join('orders', function ($join) use ($startDate, $endDate, $shiftIds) {
                $join->on('order_items.order_id', '=', 'orders.id')
                    ->where('orders.status', OrderStatus::COMPLETED);

                if ($shiftIds !== null) {
                    if (empty($shiftIds)) {
                        $join->whereRaw('1 = 0');
                    } else {
                        $join->whereIn('orders.shift_id', $shiftIds);
                    }

                    return;
                }

                $join->whereBetween('orders.created_at', [
                    $startDate ? Carbon::parse($startDate)->startOfDay() : now()->subDays(30)->startOfDay(),
                    $endDate ? Carbon::parse($endDate)->endOfDay() : now()->endOfDay(),
                ]);
            })
            ->first();

        $mostProductQuery = DB::table('products as p')
            ->whereNot('p.type', ProductType::RawMaterial)
            ->select([
                'p.id',
                'p.name',
                DB::raw('SUM(order_items.quantity) as total_quantity'),
                DB::raw('SUM(order_items.total) as total_sales'),
                DB::raw('SUM(order_items.total - (p.cost * order_items.quantity)) as total_profit'),
            ])
            ->join('order_items', 'p.id', '=', 'order_items.product_id')
            ->join('orders', function ($join) use ($startDate, $endDate, $shiftIds) {
                $join->on('order_items.order_id', '=', 'orders.id')
                    ->where('orders.status', OrderStatus::COMPLETED);

                if ($shiftIds !== null) {
                    if (empty($shiftIds)) {
                        $join->whereRaw('1 = 0');
                    } else {
                        $join->whereIn('orders.shift_id', $shiftIds);
                    }

                    return;
                }

                $join->whereBetween('orders.created_at', [
                    $startDate ? Carbon::parse($startDate)->startOfDay() : now()->subDays(30)->startOfDay(),
                    $endDate ? Carbon::parse($endDate)->endOfDay() : now()->endOfDay(),
                ]);
            })
            ->groupBy('p.id', 'p.name');

        $mostProfitableProduct = (clone $mostProductQuery)
            ->orderByDesc('total_profit')
            ->limit(1)
            ->first();

        $bestSellingProduct = (clone $mostProductQuery)
            ->orderByDesc('total_sales')
            ->limit(1)
            ->first();

        if (! $summary || ! $summary->total_sales) {
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

        return [
            'total_products' => (int) $summary->total_products,
            'total_sales' => (float) $summary->total_sales,
            'total_profit' => (float) $summary->total_profit,
            'total_quantity' => (float) $summary->total_quantity,
            'avg_profit_margin' => (float) $summary->avg_profit_margin,
            'best_selling_product' => $bestSellingProduct,
            'most_profitable_product' => $mostProfitableProduct,
        ];
    }

    /**
     * Get order type performance summary
     */
    public function getOrderTypePerformance(?string $startDate = null, ?string $endDate = null, ?array $shiftIds = null): array
    {

        $performanceData = DB::table('orders as o')
            ->select([
                'o.type',
                DB::raw('COUNT(DISTINCT o.id) as total_orders'),
                DB::raw('SUM(oi.quantity) as total_quantity'),
                DB::raw('SUM(oi.total) as total_sales'),
                DB::raw('SUM(oi.total - (p.cost * oi.quantity)) as total_profit'),
            ])
            ->leftJoin('order_items as oi', 'o.id', '=', 'oi.order_id')
            ->leftJoin('products as p', 'oi.product_id', '=', 'p.id')
            ->where('o.status', OrderStatus::COMPLETED)
            ->when($shiftIds !== null, function ($query) use ($shiftIds) {
                if (empty($shiftIds)) {
                    return $query->whereRaw('1 = 0');
                }

                return $query->whereIn('o.shift_id', $shiftIds);
            }, function ($query) use ($startDate, $endDate) {
                return $query
                    ->when($startDate, function ($query) use ($startDate) {
                        $query->where('o.created_at', '>=', Carbon::parse($startDate)->startOfDay());
                    })
                    ->when($endDate, function ($query) use ($endDate) {
                        $query->where('o.created_at', '<=', Carbon::parse($endDate)->endOfDay());
                    });
            })
            ->groupBy('o.type')
            ->get();

        // dd($performanceData);
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
        }, app(ReportOrderTypesService::class)->forQuery($this->getOrdersQuery($startDate, $endDate, $shiftIds)));
    }

    /**
     * Get category performance summary
     */
    public function getCategoryPerformance(?string $startDate = null, ?string $endDate = null, ?array $shiftIds = null)
    {
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
            ->leftJoin('products', function ($join) {
                $join->on('categories.id', '=', 'products.category_id')
                    ->whereNot('products.type', ProductType::RawMaterial);
            })
            ->leftJoin('order_items', function ($join) use ($startDate, $endDate, $shiftIds) {
                $join->on('products.id', '=', 'order_items.product_id')
                    ->whereExists(function ($query) use ($startDate, $endDate, $shiftIds) {
                        $query->select(DB::raw(1))
                            ->from('orders')
                            ->whereColumn('orders.id', 'order_items.order_id')
                            ->where('orders.status', OrderStatus::COMPLETED);

                        if ($shiftIds !== null) {
                            if (empty($shiftIds)) {
                                $query->whereRaw('1 = 0');
                            } else {
                                $query->whereIn('orders.shift_id', $shiftIds);
                            }

                            return;
                        }

                        $query->whereBetween('orders.created_at', [
                            $startDate ? Carbon::parse($startDate)->startOfDay() : now()->subDays(30)->startOfDay(),
                            $endDate ? Carbon::parse($endDate)->endOfDay() : now()->endOfDay(),
                        ]);
                    });
            })
            ->leftJoin('orders', function ($join) use ($startDate, $endDate, $shiftIds) {
                $join->on('order_items.order_id', '=', 'orders.id')
                    ->where('orders.status', OrderStatus::COMPLETED);

                if ($shiftIds !== null) {
                    if (empty($shiftIds)) {
                        $join->whereRaw('1 = 0');
                    } else {
                        $join->whereIn('orders.shift_id', $shiftIds);
                    }

                    return;
                }

                $join->whereBetween('orders.created_at', [
                    $startDate ? Carbon::parse($startDate)->startOfDay() : now()->subDays(30)->startOfDay(),
                    $endDate ? Carbon::parse($endDate)->endOfDay() : now()->endOfDay(),
                ]);
            })
            ->groupBy('categories.id', 'categories.name');
    }

    /**
     * Get period info for display
     */
    public function getPeriodInfo(?string $startDate = null, ?string $endDate = null, ?array $shiftIds = null): array
    {
        if ($shiftIds !== null) {
            $count = count($shiftIds);

            if ($count === 0) {
                return [
                    'title' => 'تقرير أداء المنتجات',
                    'description' => 'لا توجد شفتات محددة لعرض بيانات المبيعات والأرباح',
                ];
            }

            return [
                'title' => 'تقرير أداء المنتجات',
                'description' => sprintf('أداء المبيعات والأرباح للشفتات المحددة (%d شفت)', $count),
            ];
        }

        if (! $startDate && ! $endDate) {
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
