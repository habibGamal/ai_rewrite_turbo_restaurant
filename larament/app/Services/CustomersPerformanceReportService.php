<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Reports\CustomerReportThresholds;
use App\Services\Reports\ReportOrderTypesService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Customer sales figures are net: orders.total (after item and order
 * discounts) minus refunds, matching the other sales reports. Fully
 * returned orders are not counted as orders.
 */
class CustomersPerformanceReportService
{
    public function __construct(private readonly CustomerReportThresholds $thresholds) {}

    public function getOrdersQuery(?string $startDate = null, ?string $endDate = null)
    {
        return Order::query()
            ->where('status', OrderStatus::COMPLETED)
            ->whereNotNull('orders.customer_id')
            ->when($startDate, fn ($query) => $query->where('orders.created_at', '>=', Carbon::parse($startDate)->startOfDay()))
            ->when($endDate, fn ($query) => $query->where('orders.created_at', '<=', Carbon::parse($endDate)->endOfDay()));
    }

    /**
     * One row per completed order with net amounts (after discounts and returns).
     */
    public function orderFactsQuery(?string $startDate = null, ?string $endDate = null): QueryBuilder
    {
        $items = DB::table('order_items')
            ->select('order_id')
            ->selectRaw('SUM(quantity) as qty')
            ->groupBy('order_id');

        $returns = DB::table('order_return_items as ori')
            ->join('order_returns as orr', 'orr.id', '=', 'ori.order_return_id')
            ->leftJoin('order_items as roi', 'roi.id', '=', 'ori.order_item_id')
            ->select('orr.order_id')
            ->selectRaw('SUM(ori.refund_amount) as refund')
            ->selectRaw('SUM(CASE WHEN ori.order_item_id IS NOT NULL THEN ori.quantity ELSE 0 END) as qty')
            ->selectRaw('SUM(CASE WHEN ori.order_item_id IS NOT NULL AND ori.restocked = 1 THEN ori.quantity * COALESCE(roi.cost, 0) ELSE 0 END) as cost_back')
            ->groupBy('orr.order_id');

        return DB::table('orders')
            ->leftJoinSub($items, 'oi', 'oi.order_id', '=', 'orders.id')
            ->leftJoinSub($returns, 'rt', 'rt.order_id', '=', 'orders.id')
            ->where('orders.status', OrderStatus::COMPLETED->value)
            ->when($startDate, fn ($query) => $query->where('orders.created_at', '>=', Carbon::parse($startDate)->startOfDay()))
            ->when($endDate, fn ($query) => $query->where('orders.created_at', '<=', Carbon::parse($endDate)->endOfDay()))
            ->select(['orders.id', 'orders.customer_id', 'orders.type', 'orders.created_at'])
            ->selectRaw("CASE WHEN COALESCE(orders.return_status, 'none') = 'full_return' THEN 0 ELSE 1 END as counted")
            ->selectRaw('COALESCE(orders.total, 0) - COALESCE(rt.refund, 0) as net_sales')
            ->selectRaw('COALESCE(orders.profit, 0) - COALESCE(rt.refund, 0) + COALESCE(rt.cost_back, 0) as net_profit')
            ->selectRaw('COALESCE(oi.qty, 0) - COALESCE(rt.qty, 0) as net_qty')
            ->selectRaw('COALESCE(orders.discount, 0) as discount')
            ->selectRaw('COALESCE(rt.refund, 0) as refund');
    }

    /** @return Builder<Customer> */
    public function getCustomersPerformanceQuery(?string $startDate = null, ?string $endDate = null): Builder
    {
        $facts = $this->orderFactsQuery($startDate, $endDate)->whereNotNull('orders.customer_id');
        $t = $this->thresholds->all();

        $query = Customer::query()
            ->joinSub($facts, 'f', 'f.customer_id', '=', 'customers.id')
            ->select([
                'customers.id',
                'customers.name',
                'customers.phone',
                'customers.region',
                'customers.delivery_cost',
            ])
            ->selectRaw('SUM(f.counted) as total_orders')
            ->selectRaw('SUM(f.net_qty) as total_quantity')
            ->selectRaw('SUM(f.net_sales) as total_sales')
            ->selectRaw('SUM(f.net_profit) as total_profit')
            ->selectRaw('SUM(f.discount) as total_discount')
            ->selectRaw('SUM(f.refund) as total_refunds')
            ->selectRaw('CASE WHEN SUM(f.counted) > 0 THEN SUM(f.net_sales) / SUM(f.counted) ELSE 0 END as avg_order_value')
            ->selectRaw('MAX(f.created_at) as last_order_date')
            ->selectRaw('MIN(f.created_at) as first_order_date')
            // Thresholds are inlined as numeric literals: SQLite never treats a
            // number as >= a string, and PDO binds floats as strings.
            ->selectRaw(
                'CASE
                    WHEN SUM(f.net_sales) >= '.self::sqlNumber($t['vip_min_sales']).' AND SUM(f.counted) >= '.self::sqlNumber($t['vip_min_orders']).' THEN ?
                    WHEN SUM(f.net_sales) >= '.self::sqlNumber($t['loyal_min_sales']).' AND SUM(f.counted) >= '.self::sqlNumber($t['loyal_min_orders']).' THEN ?
                    WHEN SUM(f.counted) >= '.self::sqlNumber($t['regular_min_orders']).' THEN ?
                    ELSE ?
                END as customer_segment',
                [
                    CustomerReportThresholds::SEGMENT_VIP,
                    CustomerReportThresholds::SEGMENT_LOYAL,
                    CustomerReportThresholds::SEGMENT_REGULAR,
                    CustomerReportThresholds::SEGMENT_OCCASIONAL,
                ]
            )
            ->groupBy('customers.id', 'customers.name', 'customers.phone', 'customers.region', 'customers.delivery_cost')
            ->havingRaw('SUM(f.counted) > 0');

        foreach ($this->getReportOrderTypes($startDate, $endDate) as $type) {
            $code = $type['code'];
            foreach (['quantity' => 'f.net_qty', 'sales' => 'f.net_sales', 'profit' => 'f.net_profit'] as $metric => $column) {
                $query->selectRaw("COALESCE(SUM(CASE WHEN f.type = ? THEN {$column} ELSE 0 END), 0) as type_{$code}_{$metric}", [$code]);
            }
            $query->selectRaw("COALESCE(SUM(CASE WHEN f.type = ? THEN f.counted ELSE 0 END), 0) as type_{$code}_orders", [$code]);
        }

        return $query;
    }

    public function getReportOrderTypes(?string $startDate = null, ?string $endDate = null): array
    {
        return app(ReportOrderTypesService::class)->forQuery($this->getOrdersQuery($startDate, $endDate));
    }

    /** @return Collection<int, Customer> */
    public function getCustomersPerformance(?string $startDate = null, ?string $endDate = null): Collection
    {
        return $this->getCustomersPerformanceQuery($startDate, $endDate)->get();
    }

    /**
     * Headline numbers for the period and the previous period of the same length.
     *
     * @return array{current: array<string, float>, previous: array<string, float>}
     */
    public function getKpis(string $startDate, string $endDate): array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();
        $days = (int) $start->diffInDays($end) + 1;
        $previousEnd = $start->copy()->subDay();
        $previousStart = $previousEnd->copy()->subDays($days - 1);

        return [
            'current' => $this->periodTotals($start->toDateString(), $end->toDateString()),
            'previous' => $this->periodTotals($previousStart->toDateString(), $previousEnd->toDateString()),
        ];
    }

    /** @return array<string, float> */
    private function periodTotals(string $startDate, string $endDate): array
    {
        $facts = $this->orderFactsQuery($startDate, $endDate);

        $totals = DB::query()->fromSub($facts, 'f')
            ->selectRaw('COALESCE(SUM(f.net_sales), 0) as all_sales')
            ->selectRaw('COALESCE(SUM(CASE WHEN f.customer_id IS NOT NULL THEN f.net_sales ELSE 0 END), 0) as sales')
            ->selectRaw('COALESCE(SUM(CASE WHEN f.customer_id IS NOT NULL THEN f.net_profit ELSE 0 END), 0) as profit')
            ->selectRaw('COALESCE(SUM(CASE WHEN f.customer_id IS NOT NULL THEN f.counted ELSE 0 END), 0) as orders')
            ->first();

        $perCustomer = DB::query()->fromSub($facts, 'f')
            ->whereNotNull('f.customer_id')
            ->select('f.customer_id')
            ->selectRaw('SUM(f.counted) as orders')
            ->groupBy('f.customer_id');

        $counts = DB::query()->fromSub($perCustomer, 'c')
            ->selectRaw('COALESCE(SUM(CASE WHEN c.orders > 0 THEN 1 ELSE 0 END), 0) as customers')
            ->selectRaw('COALESCE(SUM(CASE WHEN c.orders > 1 THEN 1 ELSE 0 END), 0) as returning_customers')
            ->first();

        $firstOrders = DB::table('orders')
            ->where('status', OrderStatus::COMPLETED->value)
            ->whereNotNull('customer_id')
            ->select('customer_id')
            ->selectRaw('MIN(created_at) as first_at')
            ->groupBy('customer_id');

        $newCustomers = DB::query()->fromSub($firstOrders, 'fo')
            ->where('fo.first_at', '>=', Carbon::parse($startDate)->startOfDay())
            ->where('fo.first_at', '<=', Carbon::parse($endDate)->endOfDay())
            ->count();

        $sales = (float) $totals->sales;
        $orders = (float) $totals->orders;
        $customers = (float) $counts->customers;

        return [
            'customers' => $customers,
            'new_customers' => (float) $newCustomers,
            'sales' => $sales,
            'all_sales' => (float) $totals->all_sales,
            'sales_share' => $totals->all_sales > 0 ? $sales / $totals->all_sales * 100 : 0.0,
            'profit' => (float) $totals->profit,
            'margin' => $sales > 0 ? $totals->profit / $sales * 100 : 0.0,
            'orders' => $orders,
            'avg_order_value' => $orders > 0 ? $sales / $orders : 0.0,
            'return_rate' => $customers > 0 ? $counts->returning_customers / $customers * 100 : 0.0,
        ];
    }

    /**
     * Customers who order regularly but are now overdue: no order for at least
     * churn_gap_multiplier x their usual gap and at least churn_min_days.
     * Uses all history, not the selected period.
     *
     * @return Builder<Customer>
     */
    public function getAtRiskCustomersQuery(): Builder
    {
        $t = $this->thresholds->all();
        $now = now()->toDateTimeString();

        $history = DB::query()->fromSub($this->orderFactsQuery()->whereNotNull('orders.customer_id'), 'f')
            ->select('f.customer_id')
            ->selectRaw('SUM(f.counted) as orders_count')
            ->selectRaw('SUM(f.net_sales) as lifetime_sales')
            ->selectRaw('MIN(f.created_at) as first_at')
            ->selectRaw('MAX(f.created_at) as last_at')
            ->groupBy('f.customer_id');

        $daysSince = $this->daysBetween('?', 'h.last_at');
        $usualGap = '('.$this->daysBetween('h.last_at', 'h.first_at').') / (h.orders_count - 1)';

        return Customer::query()
            ->joinSub($history, 'h', 'h.customer_id', '=', 'customers.id')
            ->where('h.orders_count', '>=', 2)
            ->select(['customers.id', 'customers.name', 'customers.phone', 'customers.region'])
            ->addSelect(['h.orders_count', 'h.lifetime_sales', 'h.last_at'])
            ->selectRaw("{$daysSince} as days_since_last", [$now])
            ->selectRaw("{$usualGap} as usual_gap")
            ->whereRaw("{$daysSince} >= ".self::sqlNumber($t['churn_min_days']), [$now])
            ->whereRaw("{$daysSince} >= ".self::sqlNumber($t['churn_gap_multiplier'])." * {$usualGap}", [$now]);
    }

    /** A validated threshold as a SQL numeric literal. */
    private static function sqlNumber(float $value): string
    {
        return number_format(max(0.0, $value), 4, '.', '');
    }

    private function daysBetween(string $later, string $earlier): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "(julianday({$later}) - julianday({$earlier}))"
            : "TIMESTAMPDIFF(SECOND, {$earlier}, {$later}) / 86400";
    }

    public function getOrderTypePerformance(?string $startDate = null, ?string $endDate = null): array
    {
        $performanceData = DB::query()
            ->fromSub($this->orderFactsQuery($startDate, $endDate)->whereNotNull('orders.customer_id'), 'f')
            ->select('f.type')
            ->selectRaw('COUNT(DISTINCT f.customer_id) as unique_customers')
            ->selectRaw('SUM(f.counted) as total_orders')
            ->selectRaw('SUM(f.net_qty) as total_quantity')
            ->selectRaw('SUM(f.net_sales) as total_sales')
            ->selectRaw('SUM(f.net_profit) as total_profit')
            ->groupBy('f.type')
            ->get()
            ->keyBy('type');

        return array_map(function (array $type) use ($performanceData): array {
            $item = $performanceData->get($type['code']);

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
     * @return array<string, array{count: int, total_sales: float, total_profit: float}>
     */
    public function getCustomerSegments(?string $startDate = null, ?string $endDate = null): array
    {
        $segments = [];
        foreach (array_keys(CustomerReportThresholds::segmentColors()) as $segment) {
            $segments[$segment] = ['count' => 0, 'total_sales' => 0.0, 'total_profit' => 0.0];
        }

        $rows = DB::query()
            ->fromSub($this->getCustomersPerformanceQuery($startDate, $endDate)->toBase(), 'c')
            ->select('c.customer_segment')
            ->selectRaw('COUNT(*) as customers')
            ->selectRaw('SUM(c.total_sales) as sales')
            ->selectRaw('SUM(c.total_profit) as profit')
            ->groupBy('c.customer_segment')
            ->get();

        foreach ($rows as $row) {
            $segments[$row->customer_segment] = [
                'count' => (int) $row->customers,
                'total_sales' => (float) $row->sales,
                'total_profit' => (float) $row->profit,
            ];
        }

        return $segments;
    }

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
            $description = sprintf('أداء المبيعات والأرباح من %s إلى %s', $start->format('d/m/Y'), $end->format('d/m/Y'));
        } elseif ($start) {
            $description = sprintf('أداء المبيعات والأرباح من %s حتى الآن', $start->format('d/m/Y'));
        } else {
            $description = sprintf('أداء المبيعات والأرباح حتى %s', $end->format('d/m/Y'));
        }

        return ['title' => 'تقرير أداء العملاء', 'description' => $description];
    }

    /** @return array{0: string, 1: string} */
    public static function defaultPeriod(): array
    {
        return [now()->subDays(29)->toDateString(), now()->toDateString()];
    }
}
