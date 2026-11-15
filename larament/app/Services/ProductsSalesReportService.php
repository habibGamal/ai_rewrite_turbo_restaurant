<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Services\Reports\ReportOrderTypesService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
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

    public function getProductsSalesPerformanceQuery(?string $startDate = null, ?string $endDate = null, ?array $shiftIds = null, ?int $mainCategoryId = null, ?int $subCategoryId = null)
    {
        $subCategoryIdSql = 'CASE WHEN categories.parent_id IS NULL THEN NULL ELSE categories.id END';
        $subCategoryNameSql = 'CASE WHEN categories.parent_id IS NULL THEN NULL ELSE categories.name END';

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
                'main_categories.id as main_category_id',
                'main_categories.name as main_category_name',
                // Total sales across all order types
                DB::raw('COALESCE(SUM(order_items.quantity), 0) as total_quantity'),
                DB::raw('COALESCE(SUM(order_items.total), 0) as total_sales'),
                DB::raw('COALESCE(SUM(order_items.total - (order_items.cost * order_items.quantity)), 0) as total_profit'),

            ])
            ->selectRaw("{$subCategoryIdSql} as sub_category_id")
            ->selectRaw("{$subCategoryNameSql} as sub_category_name")
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->leftJoin('categories as main_categories', 'main_categories.id', '=', DB::raw('COALESCE(categories.parent_id, categories.id)'))
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
            ->groupBy(
                'products.id',
                'products.name',
                'products.price',
                'products.cost',
                'products.type',
                'categories.id',
                'categories.name',
                'categories.parent_id',
                'main_categories.id',
                'main_categories.name',
            )
            ->groupByRaw($subCategoryIdSql)
            ->groupByRaw($subCategoryNameSql)
            ->when($subCategoryId !== null, fn ($filtered) => $filtered->where('products.category_id', $subCategoryId))
            ->when($mainCategoryId !== null && $subCategoryId === null, function ($filtered) use ($mainCategoryId) {
                $filtered->where(function ($inner) use ($mainCategoryId) {
                    $inner->where('categories.id', $mainCategoryId)
                        ->orWhere('categories.parent_id', $mainCategoryId);
                });
            });

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
    public function getPeriodSummary(?string $startDate = null, ?string $endDate = null, ?array $shiftIds = null, ?int $mainCategoryId = null, ?int $subCategoryId = null): array
    {
        $summary = DB::table('products as p')
            ->whereNot('p.type', ProductType::RawMaterial)
            ->leftJoin('categories', 'p.category_id', '=', 'categories.id')
            ->select([
                DB::raw('COUNT(DISTINCT order_items.product_id) as total_products'),
                DB::raw('SUM(order_items.total) as total_sales'),
                DB::raw('SUM(order_items.total - (order_items.cost * order_items.quantity)) as total_profit'),
                DB::raw('SUM(order_items.quantity) as total_quantity'),
                DB::raw('AVG((order_items.total - (order_items.cost * order_items.quantity)) * 100.0 / order_items.total) as avg_profit_margin'),
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
            ->when($subCategoryId !== null, fn ($filtered) => $filtered->where('p.category_id', $subCategoryId))
            ->when($mainCategoryId !== null && $subCategoryId === null, function ($filtered) use ($mainCategoryId) {
                $filtered->where(function ($inner) use ($mainCategoryId) {
                    $inner->where('categories.id', $mainCategoryId)
                        ->orWhere('categories.parent_id', $mainCategoryId);
                });
            })
            ->first();

        $mostProductQuery = DB::table('products as p')
            ->whereNot('p.type', ProductType::RawMaterial)
            ->leftJoin('categories', 'p.category_id', '=', 'categories.id')
            ->select([
                'p.id',
                'p.name',
                DB::raw('SUM(order_items.quantity) as total_quantity'),
                DB::raw('SUM(order_items.total) as total_sales'),
                DB::raw('SUM(order_items.total - (order_items.cost * order_items.quantity)) as total_profit'),
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
            ->when($subCategoryId !== null, fn ($filtered) => $filtered->where('p.category_id', $subCategoryId))
            ->when($mainCategoryId !== null && $subCategoryId === null, function ($filtered) use ($mainCategoryId) {
                $filtered->where(function ($inner) use ($mainCategoryId) {
                    $inner->where('categories.id', $mainCategoryId)
                        ->orWhere('categories.parent_id', $mainCategoryId);
                });
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
    public function getOrderTypePerformance(?string $startDate = null, ?string $endDate = null, ?array $shiftIds = null, ?int $mainCategoryId = null, ?int $subCategoryId = null): array
    {

        $performanceData = DB::table('orders as o')
            ->select([
                'o.type',
                DB::raw('COUNT(DISTINCT o.id) as total_orders'),
                DB::raw('SUM(oi.quantity) as total_quantity'),
                DB::raw('SUM(oi.total) as total_sales'),
                DB::raw('SUM(oi.total - (oi.cost * oi.quantity)) as total_profit'),
            ])
            ->leftJoin('order_items as oi', 'o.id', '=', 'oi.order_id')
            ->leftJoin('products as p', 'oi.product_id', '=', 'p.id')
            ->leftJoin('categories', 'p.category_id', '=', 'categories.id')
            ->where('o.status', OrderStatus::COMPLETED)
            ->when($subCategoryId !== null, fn ($filtered) => $filtered->where('p.category_id', $subCategoryId))
            ->when($mainCategoryId !== null && $subCategoryId === null, function ($filtered) use ($mainCategoryId) {
                $filtered->where(function ($inner) use ($mainCategoryId) {
                    $inner->where('categories.id', $mainCategoryId)
                        ->orWhere('categories.parent_id', $mainCategoryId);
                });
            })
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
     * Sales grouped main category → sub-category → product, with subtotals.
     *
     * @return list<array{
     *     main_category_id: int,
     *     main_category_name: string,
     *     total_quantity: float,
     *     total_sales: float,
     *     total_profit: float,
     *     sub_categories: list<array{
     *         sub_category_id: int|null,
     *         sub_category_name: string|null,
     *         total_quantity: float,
     *         total_sales: float,
     *         total_profit: float,
     *         products: list<array{id: int, name: string, total_quantity: float, total_sales: float, total_profit: float}>
     *     }>
     * }>
     */
    public function groupedProductSales(?string $startDate = null, ?string $endDate = null, ?array $shiftIds = null, ?int $mainCategoryId = null, ?int $subCategoryId = null): array
    {
        $rows = $this->getProductsSalesPerformanceQuery($startDate, $endDate, $shiftIds, $mainCategoryId, $subCategoryId)
            ->orderBy('main_category_name')
            ->orderBy('sub_category_name')
            ->orderBy('products.name')
            ->get();

        $groups = [];

        foreach ($rows as $row) {
            $mainId = (int) $row->main_category_id;
            $subId = $row->sub_category_id !== null ? (int) $row->sub_category_id : null;
            $subKey = $subId === null ? 'direct' : (string) $subId;
            $quantity = (float) $row->total_quantity;
            $sales = (float) $row->total_sales;
            $profit = (float) $row->total_profit;

            if (! isset($groups[$mainId])) {
                $groups[$mainId] = [
                    'main_category_id' => $mainId,
                    'main_category_name' => (string) $row->main_category_name,
                    'total_quantity' => 0.0,
                    'total_sales' => 0.0,
                    'total_profit' => 0.0,
                    'sub_categories' => [],
                ];
            }

            if (! isset($groups[$mainId]['sub_categories'][$subKey])) {
                $groups[$mainId]['sub_categories'][$subKey] = [
                    'sub_category_id' => $subId,
                    'sub_category_name' => $subId === null ? null : (string) $row->sub_category_name,
                    'total_quantity' => 0.0,
                    'total_sales' => 0.0,
                    'total_profit' => 0.0,
                    'products' => [],
                ];
            }

            $groups[$mainId]['sub_categories'][$subKey]['products'][] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'total_quantity' => $quantity,
                'total_sales' => $sales,
                'total_profit' => $profit,
            ];
            $groups[$mainId]['sub_categories'][$subKey]['total_quantity'] += $quantity;
            $groups[$mainId]['sub_categories'][$subKey]['total_sales'] += $sales;
            $groups[$mainId]['sub_categories'][$subKey]['total_profit'] += $profit;
            $groups[$mainId]['total_quantity'] += $quantity;
            $groups[$mainId]['total_sales'] += $sales;
            $groups[$mainId]['total_profit'] += $profit;
        }

        return array_values(array_map(function (array $group): array {
            $group['sub_categories'] = array_values($group['sub_categories']);

            return $group;
        }, $groups));
    }

    /**
     * Get summary of all main categories with sales in the period/shifts.
     *
     * @return list<array{
     *     main_category_id: int,
     *     main_category_name: string,
     *     total_quantity: float,
     *     total_sales: float,
     *     total_profit: float,
     *     products_count: int
     * }>
     */
    public function getMainCategoriesSalesSummary(?string $startDate = null, ?string $endDate = null, ?array $shiftIds = null, ?int $mainCategoryId = null, ?int $subCategoryId = null): array
    {
        $query = DB::table('products as p')
            ->whereNot('p.type', ProductType::RawMaterial)
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('categories as mc', 'mc.id', '=', DB::raw('COALESCE(c.parent_id, c.id)'))
            ->join('order_items as oi', 'p.id', '=', 'oi.product_id')
            ->join('orders as o', function ($join) use ($startDate, $endDate, $shiftIds) {
                $join->on('oi.order_id', '=', 'o.id')
                    ->where('o.status', OrderStatus::COMPLETED);

                if ($shiftIds !== null) {
                    if (empty($shiftIds)) {
                        $join->whereRaw('1 = 0');
                    } else {
                        $join->whereIn('o.shift_id', $shiftIds);
                    }

                    return;
                }

                $join->whereBetween('o.created_at', [
                    $startDate ? Carbon::parse($startDate)->startOfDay() : now()->subDays(30)->startOfDay(),
                    $endDate ? Carbon::parse($endDate)->endOfDay() : now()->endOfDay(),
                ]);
            })
            ->when($subCategoryId !== null, fn ($filtered) => $filtered->where('p.category_id', $subCategoryId))
            ->when($mainCategoryId !== null && $subCategoryId === null, function ($filtered) use ($mainCategoryId) {
                $filtered->where(function ($inner) use ($mainCategoryId) {
                    $inner->where('c.id', $mainCategoryId)
                        ->orWhere('c.parent_id', $mainCategoryId);
                });
            });

        /** @var Collection<int, object> $rows */
        $rows = $query->select([
            DB::raw('COALESCE(mc.id, 0) as main_category_id'),
            DB::raw('COALESCE(mc.name, "غير مصنف") as main_category_name'),
            DB::raw('COALESCE(SUM(oi.quantity), 0) as total_quantity'),
            DB::raw('COALESCE(SUM(oi.total), 0) as total_sales'),
            DB::raw('COALESCE(SUM(oi.total - (oi.cost * oi.quantity)), 0) as total_profit'),
            DB::raw('COUNT(DISTINCT p.id) as products_count'),
        ])
            ->groupBy('mc.id', 'mc.name')
            ->orderBy('main_category_name')
            ->get();

        return $rows->map(fn (object $row): array => [
            'main_category_id' => (int) $row->main_category_id,
            'main_category_name' => (string) $row->main_category_name,
            'total_quantity' => (float) $row->total_quantity,
            'total_sales' => (float) $row->total_sales,
            'total_profit' => (float) $row->total_profit,
            'products_count' => (int) $row->products_count,
        ])->all();
    }

    /**
     * Sales rolled up by product group (retail model): all sizes and colours
     * of a model in one row, with how many of its SKUs sold and the best one.
     *
     * @return list<array{
     *     group_id: int,
     *     group_name: string,
     *     total_quantity: float,
     *     total_sales: float,
     *     total_profit: float,
     *     variants_sold: int,
     *     variants_total: int,
     *     top_variant: string|null
     * }>
     */
    public function getProductGroupSales(?string $startDate = null, ?string $endDate = null, ?array $shiftIds = null, ?int $mainCategoryId = null, ?int $subCategoryId = null): array
    {
        $perVariant = DB::table('products as p')
            ->join('product_groups as g', 'g.id', '=', 'p.product_group_id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->join('order_items as oi', 'p.id', '=', 'oi.product_id')
            ->join('orders as o', function ($join) use ($startDate, $endDate, $shiftIds) {
                $join->on('oi.order_id', '=', 'o.id')
                    ->where('o.status', OrderStatus::COMPLETED);

                if ($shiftIds !== null) {
                    if (empty($shiftIds)) {
                        $join->whereRaw('1 = 0');
                    } else {
                        $join->whereIn('o.shift_id', $shiftIds);
                    }

                    return;
                }

                $join->whereBetween('o.created_at', [
                    $startDate ? Carbon::parse($startDate)->startOfDay() : now()->subDays(30)->startOfDay(),
                    $endDate ? Carbon::parse($endDate)->endOfDay() : now()->endOfDay(),
                ]);
            })
            ->when($subCategoryId !== null, fn ($filtered) => $filtered->where('p.category_id', $subCategoryId))
            ->when($mainCategoryId !== null && $subCategoryId === null, function ($filtered) use ($mainCategoryId) {
                $filtered->where(function ($inner) use ($mainCategoryId) {
                    $inner->where('c.id', $mainCategoryId)
                        ->orWhere('c.parent_id', $mainCategoryId);
                });
            })
            ->select([
                'g.id as group_id',
                'g.name as group_name',
                'p.id as product_id',
                'p.variant_label',
                DB::raw('SUM(oi.quantity) as quantity'),
                DB::raw('SUM(oi.total) as sales'),
                DB::raw('SUM(oi.total - (oi.cost * oi.quantity)) as profit'),
            ])
            ->groupBy('g.id', 'g.name', 'p.id', 'p.variant_label')
            ->get();

        if ($perVariant->isEmpty()) {
            return [];
        }

        $variantCounts = DB::table('products')
            ->whereIn('product_group_id', $perVariant->pluck('group_id')->unique()->all())
            ->selectRaw('product_group_id, COUNT(*) as total')
            ->groupBy('product_group_id')
            ->pluck('total', 'product_group_id');

        return $perVariant
            ->groupBy('group_id')
            ->map(function ($rows, $groupId) use ($variantCounts): array {
                $top = $rows->sortByDesc(fn (object $row): float => (float) $row->quantity)->first();

                return [
                    'group_id' => (int) $groupId,
                    'group_name' => (string) $rows->first()->group_name,
                    'total_quantity' => (float) $rows->sum(fn (object $row): float => (float) $row->quantity),
                    'total_sales' => (float) $rows->sum(fn (object $row): float => (float) $row->sales),
                    'total_profit' => (float) $rows->sum(fn (object $row): float => (float) $row->profit),
                    'variants_sold' => $rows->count(),
                    'variants_total' => (int) ($variantCounts[$groupId] ?? $rows->count()),
                    'top_variant' => $top?->variant_label,
                ];
            })
            ->sortByDesc('total_sales')
            ->values()
            ->all();
    }

    public static function nullableFilterId(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Get category performance summary, rolled up to the main category or by secondary category.
     */
    public function getCategoryPerformance(
        ?string $startDate = null,
        ?string $endDate = null,
        ?array $shiftIds = null,
        ?int $mainCategoryId = null,
        ?int $subCategoryId = null,
        string $mode = 'main'
    ) {
        $isSecondary = ($mode === 'secondary');

        $query = Category::query();

        if ($isSecondary) {
            $query->whereNotNull('categories.parent_id')
                ->leftJoin('categories as parent_categories', 'categories.parent_id', '=', 'parent_categories.id')
                ->when($mainCategoryId !== null, fn ($q) => $q->where('categories.parent_id', $mainCategoryId))
                ->when($subCategoryId !== null, fn ($q) => $q->where('categories.id', $subCategoryId));
        } else {
            $query->whereNull('categories.parent_id')
                ->when($mainCategoryId !== null, fn ($q) => $q->where('categories.id', $mainCategoryId))
                ->when($subCategoryId !== null && $mainCategoryId === null, function ($q) use ($subCategoryId) {
                    $q->whereIn('categories.id', function ($sub) use ($subCategoryId) {
                        $sub->selectRaw('CASE WHEN parent_id IS NULL THEN id ELSE parent_id END')
                            ->from('categories')
                            ->where('id', $subCategoryId);
                    });
                });
        }

        $selects = [
            DB::raw('categories.id as id'),
            DB::raw('COALESCE(categories.name, "غير مصنف") as category_name'),
            DB::raw('COUNT(DISTINCT products.id) as products_count'),
            DB::raw('COALESCE(SUM(order_items.quantity), 0) as total_quantity'),
            DB::raw('COALESCE(SUM(order_items.total), 0) as total_sales'),
            DB::raw('COALESCE(SUM(order_items.total - (order_items.cost * order_items.quantity)), 0) as total_profit'),
        ];

        if ($isSecondary) {
            $selects[] = DB::raw('COALESCE(parent_categories.name, "غير محدد") as parent_category_name');
        }

        $query->select($selects);

        if ($isSecondary) {
            $query->leftJoin('products', function ($join) {
                $join->on('products.category_id', '=', 'categories.id')
                    ->whereNot('products.type', ProductType::RawMaterial);
            });
        } else {
            $query->leftJoin('products', function ($join) use ($subCategoryId) {
                $join->whereRaw('products.category_id in (select tree.id from categories as tree where tree.id = categories.id or tree.parent_id = categories.id)')
                    ->whereNot('products.type', ProductType::RawMaterial)
                    ->when($subCategoryId !== null, fn ($productJoin) => $productJoin->where('products.category_id', $subCategoryId));
            });
        }

        $query->leftJoin('order_items', function ($join) use ($startDate, $endDate, $shiftIds) {
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
        });

        $query->leftJoin('orders', function ($join) use ($startDate, $endDate, $shiftIds) {
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
        });

        $groupBys = ['categories.id', 'categories.name'];
        if ($isSecondary) {
            $groupBys[] = 'parent_categories.name';
        }

        return $query->groupBy($groupBys);
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
