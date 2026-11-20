<?php

namespace App\Services\Reports\Inventory;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Module;
use App\Services\FeatureService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * «تقرير حركة المخزون»: every stock movement of a period in one place.
 *
 * Everything is read from inventory_item_movements and valued at the cost
 * recorded on each movement (total_cost), so old figures do not move when a
 * product's cost changes. Periods use the movement's business_date.
 *
 * Movements fall into "buckets" by reason and direction (purchase,
 * consumption by orders, waste, stocktaking shortage or surplus, ...). The
 * value bridge walks from the opening value to the closing value through
 * those buckets; the items table shows the same per product.
 */
class InventoryMovementsReportService
{
    /**
     * Bucket key => [reason, operation, label, direction]. A movement whose
     * reason and operation match no row is "other" (manual, adjustment,
     * initial stock, the legacy transfer reason).
     *
     * @var array<string, array{0: ?string, 1: string, 2: string}>
     */
    private const BUCKETS = [
        'purchase' => ['purchase', 'in', 'مشتريات'],
        'purchase_return' => ['purchase_return', 'out', 'مرتجع مشتريات'],
        'order' => ['order', 'out', 'استهلاك المبيعات'],
        'order_return' => ['order_return', 'in', 'مرتجع مبيعات'],
        'waste' => ['waste', 'out', 'هالك'],
        'stock_issue' => ['stock_issue', 'out', 'صرف'],
        'stocktaking_out' => ['stocktaking', 'out', 'عجز جرد'],
        'stocktaking_in' => ['stocktaking', 'in', 'زيادة جرد'],
        'manufacturing_in' => ['manufacturing_in', 'in', 'تصنيع وارد'],
        'manufacturing_out' => ['manufacturing_out', 'out', 'تصنيع صادر'],
        'transfer_in' => ['transfer_in', 'in', 'تحويل وارد'],
        'transfer_out' => ['transfer_out', 'out', 'تحويل صادر'],
        'other_in' => [null, 'in', 'تسويات يدوية (دخول)'],
        'other_out' => [null, 'out', 'تسويات يدوية (خروج)'],
    ];

    private const MANUFACTURING = ['manufacturing_in', 'manufacturing_out'];

    private const TRANSFERS = ['transfer_in', 'transfer_out'];

    public function __construct(private readonly FeatureService $features) {}

    public function restaurant(): bool
    {
        return $this->features->moduleEnabled(Module::RESTAURANT);
    }

    public function manufacturing(): bool
    {
        return $this->features->moduleEnabled(Module::MANUFACTURING);
    }

    /** What sales consumption is called for this business. */
    public function consumptionLabel(): string
    {
        return $this->restaurant() ? 'استهلاك الطلبات' : 'تكلفة البضاعة المباعة';
    }

    public function bucketLabel(string $key): string
    {
        return $key === 'order' ? $this->consumptionLabel() : (self::BUCKETS[$key][2] ?? $key);
    }

    public static function bucketDirection(string $key): string
    {
        return self::BUCKETS[$key][1] ?? 'out';
    }

    /**
     * The bucket keys shown in this install, in table order. Manufacturing
     * rows show only where the module is on or there was such movement.
     *
     * @param  array<string, array<string, float|int>>  $totals
     * @return list<string>
     */
    public function visibleBuckets(array $totals): array
    {
        return array_values(array_filter(array_keys(self::BUCKETS), function (string $key) use ($totals): bool {
            $moved = ($totals[$key]['movements'] ?? 0) > 0;
            if (in_array($key, self::MANUFACTURING, true)) {
                return $this->manufacturing() || $moved;
            }
            if (in_array($key, ['other_in', 'other_out'], true)) {
                return $moved;
            }

            return true;
        }));
    }

    /**
     * SQL that names a movement's bucket.
     */
    public static function bucketSql(string $alias = 'm'): string
    {
        $cases = [];
        foreach (self::BUCKETS as $key => [$reason, $operation]) {
            if ($reason !== null) {
                $cases[] = "WHEN {$alias}.reason = '{$reason}' AND {$alias}.operation = '{$operation}' THEN '{$key}'";
            }
        }

        return 'CASE '.implode(' ', $cases)." WHEN {$alias}.operation = 'in' THEN 'other_in' ELSE 'other_out' END";
    }

    /**
     * Movements narrowed by warehouse, category and product (no dates).
     */
    public function movements(InventoryReportFilters $filters, string $alias = 'm'): QueryBuilder
    {
        $query = DB::table('inventory_item_movements as '.$alias);
        if ($filters->warehouseId !== null) {
            $query->where($alias.'.warehouse_id', $filters->warehouseId);
        }
        if ($filters->productId !== null) {
            $query->where($alias.'.product_id', $filters->productId);
        }
        $this->filterCategory($query, $filters, $alias.'.product_id');

        return $query;
    }

    public function filterCategory(QueryBuilder $query, InventoryReportFilters $filters, string $productColumn): void
    {
        if ($filters->subCategoryId !== null) {
            $query->whereIn($productColumn, Product::query()->select('id')->where('category_id', $filters->subCategoryId));
        } elseif ($filters->mainCategoryId !== null) {
            $query->whereIn($productColumn, Product::query()->select('id')->whereIn(
                'category_id',
                Category::query()->select('id')->where('id', $filters->mainCategoryId)->orWhere('parent_id', $filters->mainCategoryId),
            ));
        }
    }

    public static function upTo(string $date): string
    {
        return $date.' 23:59:59';
    }

    /**
     * The period's movements per bucket: value, quantity, documents, items.
     *
     * @return array<string, array{value: float, movements: int, documents: int, items: int}>
     */
    public function bucketTotals(InventoryReportFilters $filters): array
    {
        $bucket = self::bucketSql();
        $period = fn () => $this->movements($filters)->whereBetween('m.business_date', [$filters->startDate(), self::upTo($filters->endDate())]);

        // One row per bucket and document; a movement without a document is its own.
        $documents = $period()
            ->selectRaw("{$bucket} as bucket")
            ->selectRaw('COALESCE(SUM(m.total_cost), 0) as value')
            ->selectRaw('COUNT(*) as movements')
            ->groupBy('bucket')
            ->groupByRaw("COALESCE(m.referenceable_type, ''), COALESCE(m.referenceable_id, -m.id)");

        $rows = DB::query()->fromSub($documents, 'd')
            ->selectRaw('d.bucket, SUM(d.value) as value, SUM(d.movements) as movements, COUNT(*) as documents')
            ->groupBy('d.bucket')
            ->get()
            ->keyBy('bucket');

        $items = $period()
            ->selectRaw("{$bucket} as bucket, COUNT(DISTINCT m.product_id) as items")
            ->groupBy('bucket')
            ->pluck('items', 'bucket');

        $totals = [];
        foreach (array_keys(self::BUCKETS) as $key) {
            $row = $rows->get($key);
            $totals[$key] = [
                'value' => round((float) ($row->value ?? 0), 2),
                'movements' => (int) ($row->movements ?? 0),
                'documents' => (int) ($row->documents ?? 0),
                'items' => (int) ($items[$key] ?? 0),
            ];
        }

        return $totals;
    }

    /** Stock value at the start of the given day (book cost). */
    public function valueBefore(InventoryReportFilters $filters, string $date): float
    {
        return round((float) $this->movements($filters)
            ->where('m.business_date', '<', $date)
            ->selectRaw("COALESCE(SUM(CASE WHEN m.operation = 'in' THEN m.total_cost ELSE -m.total_cost END), 0) as value")
            ->value('value'), 2);
    }

    /**
     * Cost of returned lines that did not go back to stock. They create no
     * movement, so they sit beside the bridge, not in it.
     */
    public function damagedReturnsValue(InventoryReportFilters $filters): float
    {
        $query = DB::table('order_return_items as ori')
            ->join('order_returns as orr', 'orr.id', '=', 'ori.order_return_id')
            ->join('order_items as oi', 'oi.id', '=', 'ori.order_item_id')
            ->where('ori.restocked', false)
            ->whereBetween('orr.created_at', [$filters->start->startOfDay(), $filters->end->endOfDay()]);
        if ($filters->productId !== null) {
            $query->where('oi.product_id', $filters->productId);
        }
        $this->filterCategory($query, $filters, 'oi.product_id');

        return round((float) $query->selectRaw('COALESCE(SUM(ori.quantity * COALESCE(oi.cost, 0)), 0) as value')->value('value'), 2);
    }

    /**
     * The headline figures of a period.
     *
     * @return array<string, float|null>
     */
    public function summary(InventoryReportFilters $filters): array
    {
        $totals = $this->bucketTotals($filters);
        $v = fn (string $key): float => $totals[$key]['value'];

        $in = 0.0;
        $out = 0.0;
        foreach ($totals as $key => $row) {
            if (self::bucketDirection($key) === 'in') {
                $in += $row['value'];
            } else {
                $out += $row['value'];
            }
        }

        $opening = $this->valueBefore($filters, $filters->startDate());
        $closing = round($opening + $in - $out, 2);
        $consumption = round($v('order') - $v('order_return'), 2);
        $stocktakingNet = round($v('stocktaking_out') - $v('stocktaking_in'), 2);
        $loss = round($v('waste') + $stocktakingNet, 2);
        $usage = $consumption + $v('stock_issue') + $v('manufacturing_out');
        $lossBase = $consumption + $v('stock_issue') + $loss;
        $average = ($opening + $closing) / 2;
        $dailyUsage = $usage / $filters->days();

        return [
            'opening' => $opening,
            'closing' => $closing,
            'in' => round($in, 2),
            'out' => round($out, 2),
            'net' => round($closing - $opening, 2),
            'purchases' => $v('purchase'),
            'purchase_returns' => $v('purchase_return'),
            'purchase_documents' => (float) $totals['purchase']['documents'],
            'consumption' => $consumption,
            'waste' => $v('waste'),
            'stocktaking_net' => $stocktakingNet,
            'shortage' => $v('stocktaking_out'),
            'surplus' => $v('stocktaking_in'),
            'loss' => $loss,
            'loss_rate' => $lossBase > 0 ? round($loss / $lossBase * 100, 2) : null,
            'damaged_returns' => $this->damagedReturnsValue($filters),
            'turnover' => $average > 0 ? round($consumption / $average, 2) : null,
            'cover_days' => $dailyUsage > 0 ? round($closing / $dailyUsage, 1) : null,
        ];
    }

    /** Percent change, or null when there is no base to compare with. */
    public static function change(?float $now, ?float $before): ?float
    {
        if ($now === null || $before === null || $before == 0.0) {
            return null;
        }

        return round(($now - $before) / abs($before) * 100, 1);
    }

    /**
     * The table of movement types for the period, with their share of all
     * movement in the same direction and the change against the
     * comparison period.
     *
     * @return list<array{key: string, label: string, direction: string, value: float, documents: int, items: int, movements: int, share: float, change: ?float}>
     */
    public function typeRows(InventoryReportFilters $filters): array
    {
        $totals = $this->bucketTotals($filters);
        $comparison = $filters->comparison();
        $before = $comparison ? $this->bucketTotals($comparison) : null;

        $sums = ['in' => 0.0, 'out' => 0.0];
        foreach ($totals as $key => $row) {
            $sums[self::bucketDirection($key)] += $row['value'];
        }

        $rows = [];
        foreach ($this->visibleBuckets($totals) as $key) {
            $direction = self::bucketDirection($key);
            $value = $totals[$key]['value'];
            $rows[] = [
                'key' => $key,
                'label' => $this->bucketLabel($key),
                'direction' => $direction,
                'value' => $value,
                'documents' => $totals[$key]['documents'],
                'items' => $totals[$key]['items'],
                'movements' => $totals[$key]['movements'],
                'share' => $sums[$direction] > 0 ? round($value / $sums[$direction] * 100, 1) : 0.0,
                'change' => $before ? self::change($value, $before[$key]['value']) : null,
            ];
        }

        return $rows;
    }

    /**
     * The steps from the opening value to the closing value. Transfers
     * between warehouses only show when one warehouse is chosen (across all
     * warehouses they cancel out); manufacturing shows as one net step.
     *
     * @return list<array{key: ?string, label: string, from: float, to: float, kind: string}>
     */
    public function bridge(InventoryReportFilters $filters): array
    {
        $totals = $this->bucketTotals($filters);
        $v = fn (string $key): float => $totals[$key]['value'];
        $opening = $this->valueBefore($filters, $filters->startDate());

        $steps = [
            ['purchase', 'مشتريات', $v('purchase')],
            ['purchase_return', 'مرتجع مشتريات', -$v('purchase_return')],
            ['order_return', 'مرتجع مبيعات', $v('order_return')],
            ['manufacturing', 'تصنيع (صافي)', $v('manufacturing_in') - $v('manufacturing_out')],
            ['transfer', 'تحويلات (صافي)', $v('transfer_in') - $v('transfer_out')],
            ['order', $this->consumptionLabel(), -$v('order')],
            ['waste', 'هالك', -$v('waste')],
            ['stock_issue', 'صرف', -$v('stock_issue')],
            ['stocktaking', 'فروق جرد', $v('stocktaking_in') - $v('stocktaking_out')],
            ['other', 'تسويات يدوية', $v('other_in') - $v('other_out')],
        ];

        $bridge = [['key' => null, 'label' => 'أول المدة', 'from' => 0.0, 'to' => $opening, 'kind' => 'total']];
        $running = $opening;
        foreach ($steps as [$key, $label, $delta]) {
            $always = in_array($key, ['purchase', 'order'], true);
            if (! $always && abs($delta) < 0.005) {
                continue;
            }
            $next = round($running + $delta, 2);
            $bridge[] = ['key' => $key, 'label' => $label, 'from' => $running, 'to' => $next, 'kind' => $delta >= 0 ? 'in' : 'out'];
            $running = $next;
        }
        $bridge[] = ['key' => null, 'label' => 'آخر المدة', 'from' => 0.0, 'to' => $running, 'kind' => 'total'];

        return $bridge;
    }

    /**
     * The bucket keys behind a bridge step or a table row.
     *
     * @return list<string>
     */
    public static function bucketsOf(string $key): array
    {
        return match ($key) {
            'manufacturing' => self::MANUFACTURING,
            'transfer' => self::TRANSFERS,
            'stocktaking' => ['stocktaking_out', 'stocktaking_in'],
            'other' => ['other_in', 'other_out'],
            default => array_key_exists($key, self::BUCKETS) ? [$key] : [],
        };
    }

    /**
     * The documents behind some buckets in the period, biggest value first.
     *
     * @param  list<string>  $buckets
     * @return list<array{type: string, number: string, url: ?string, date: string, warehouse: ?string, user: ?string, items: int, value: float}>
     */
    public function documents(InventoryReportFilters $filters, array $buckets, int $limit = 50): array
    {
        if ($buckets === []) {
            return [];
        }

        $bucket = self::bucketSql();
        $rows = $this->movements($filters)
            ->whereBetween('m.business_date', [$filters->startDate(), self::upTo($filters->endDate())])
            ->whereRaw("{$bucket} IN (".implode(',', array_fill(0, count($buckets), '?')).')', $buckets)
            ->selectRaw('m.referenceable_type, m.referenceable_id, MIN(m.id) as first_id, MIN(m.business_date) as business_date')
            ->selectRaw('MIN(m.warehouse_id) as warehouse_id, MIN(m.user_id) as user_id, MIN(m.reason) as reason')
            ->selectRaw('COUNT(DISTINCT m.product_id) as items, COALESCE(SUM(m.total_cost), 0) as value')
            ->groupByRaw('m.referenceable_type, m.referenceable_id, CASE WHEN m.referenceable_id IS NULL THEN m.id ELSE 0 END')
            ->orderByDesc('value')
            ->limit($limit)
            ->get();

        $warehouses = Warehouse::query()->whereIn('id', $rows->pluck('warehouse_id')->filter())->pluck('name', 'id');
        $users = User::query()->whereIn('id', $rows->pluck('user_id')->filter())->pluck('name', 'id');

        return $rows->map(fn ($row) => [
            'type' => ItemLedgerReportService::documentTypeLabel($row->referenceable_type, $row->reason),
            'number' => (string) ($row->referenceable_id ?? $row->first_id),
            'url' => ItemLedgerReportService::documentUrl($row->referenceable_type, $row->referenceable_id),
            'date' => substr((string) $row->business_date, 0, 10),
            'warehouse' => $warehouses[$row->warehouse_id] ?? null,
            'user' => $users[$row->user_id] ?? null,
            'items' => (int) $row->items,
            'value' => round((float) $row->value, 2),
        ])->values()->all();
    }

    /**
     * One row per product that had stock or movement: opening, what came in
     * and went out in the period, waste, stocktaking difference, closing,
     * the loss rate and how many days the closing stock lasts.
     *
     * Transfers count as in/out only when one warehouse is chosen; across
     * all warehouses they cancel out and would only blur the columns.
     *
     * @return Builder<Product>
     */
    public function itemsQuery(InventoryReportFilters $filters): Builder
    {
        $start = $filters->startDate();
        $end = self::upTo($filters->endDate());
        $bucket = self::bucketSql();
        $inPeriod = "m.business_date >= '{$start}'";
        $countTransfers = $filters->warehouseId !== null;
        $flow = $countTransfers ? '' : " AND m.reason NOT IN ('transfer_in', 'transfer_out')";
        $signedQty = "CASE WHEN m.operation = 'in' THEN m.quantity ELSE -m.quantity END";
        $signedValue = "CASE WHEN m.operation = 'in' THEN m.total_cost ELSE -m.total_cost END";
        $notCounted = "m.reason NOT IN ('waste', 'stocktaking')";

        $movements = $this->movements($filters)
            ->where('m.business_date', '<=', $end)
            ->selectRaw('m.product_id')
            ->selectRaw("SUM(CASE WHEN m.business_date < '{$start}' THEN {$signedQty} ELSE 0 END) as opening_qty")
            ->selectRaw("SUM(CASE WHEN m.business_date < '{$start}' THEN {$signedValue} ELSE 0 END) as opening_value")
            ->selectRaw("SUM(CASE WHEN {$inPeriod} AND m.operation = 'in' AND {$notCounted}{$flow} THEN m.quantity ELSE 0 END) as in_qty")
            ->selectRaw("SUM(CASE WHEN {$inPeriod} AND m.operation = 'out' AND {$notCounted}{$flow} THEN m.quantity ELSE 0 END) as out_qty")
            ->selectRaw("SUM(CASE WHEN {$inPeriod} AND ({$bucket}) = 'waste' THEN m.quantity ELSE 0 END) as waste_qty")
            ->selectRaw("SUM(CASE WHEN {$inPeriod} AND m.reason = 'stocktaking' THEN {$signedQty} ELSE 0 END) as count_qty")
            ->selectRaw("SUM(CASE WHEN {$inPeriod} AND m.reason IN ('order', 'stock_issue', 'manufacturing_out') THEN m.quantity WHEN {$inPeriod} AND m.reason = 'order_return' THEN -m.quantity ELSE 0 END) as usage_qty")
            ->selectRaw("SUM({$signedQty}) as closing_qty")
            ->selectRaw("SUM({$signedValue}) as closing_value")
            ->selectRaw("SUM(CASE WHEN {$inPeriod} THEN 1 ELSE 0 END) as period_movements")
            ->groupBy('m.product_id');

        $days = $filters->days();
        $shortage = 'CASE WHEN a.count_qty < 0 THEN -a.count_qty ELSE 0 END';
        $lossBase = "(a.out_qty + a.waste_qty + {$shortage})";

        $rows = DB::table('products as p')
            ->joinSub($movements, 'a', 'a.product_id', '=', 'p.id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where(fn (QueryBuilder $query) => $query->where('a.period_movements', '>', 0)->orWhereRaw('ABS(a.closing_qty) > 0.0005'))
            ->select('p.id', 'p.name', 'p.unit', 'p.category_id', 'c.name as category_name')
            ->selectRaw('a.opening_qty, a.opening_value, a.in_qty, a.out_qty, a.waste_qty, a.count_qty, a.usage_qty, a.closing_qty, a.closing_value')
            ->selectRaw("CASE WHEN {$lossBase} > 0 THEN (a.waste_qty + {$shortage}) * 100.0 / {$lossBase} ELSE 0 END as loss_rate")
            ->selectRaw("CASE WHEN a.usage_qty > 0 THEN a.closing_qty * {$days} / a.usage_qty ELSE NULL END as cover_days");

        return Product::query()->fromSub($rows, 'products')->select('products.*');
    }
}
