<?php

namespace App\Services\Reports\Inventory;

use App\Enums\MovementReason;
use App\Models\PurchaseInvoice;
use App\Models\ReturnPurchaseInvoice;
use App\Models\Stocktaking;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Module;
use App\Services\FeatureService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The deeper tabs of «تقرير حركة المخزون»: losses and stocktaking,
 * purchases and cost, warehouses, and alerts. Values are book cost
 * (the movement's total_cost) like the rest of the report.
 *
 * Loss = waste + stocktaking shortage − stocktaking surplus.
 * Loss rate = loss ÷ (sales consumption + issues + loss).
 */
class InventoryInsightsReportService
{
    /** Days without a sale after which stock counts as slow-moving. */
    public const SLOW_MOVING_DAYS = 30;

    /** A count that differs from the system by this share is "large". */
    public const LARGE_VARIANCE_RATE = 20;

    /** One user behind this share of waste (with enough documents) is flagged. */
    public const WASTE_CONCENTRATION_RATE = 60;

    public function __construct(
        private readonly InventoryMovementsReportService $movements,
        private readonly FeatureService $features,
    ) {}

    public function restaurant(): bool
    {
        return $this->features->moduleEnabled(Module::RESTAURANT);
    }

    public function retail(): bool
    {
        return $this->features->moduleEnabled(Module::RETAIL);
    }

    private function period(InventoryReportFilters $filters, string $alias = 'm'): QueryBuilder
    {
        return $this->movements->movements($filters, $alias)
            ->whereBetween($alias.'.business_date', [$filters->startDate(), InventoryMovementsReportService::upTo($filters->endDate())]);
    }

    /** SQL sums used by the loss figures, per group. */
    private function lossSums(QueryBuilder $query): QueryBuilder
    {
        return $query
            ->selectRaw("COALESCE(SUM(CASE WHEN m.reason = 'waste' AND m.operation = 'out' THEN m.total_cost ELSE 0 END), 0) as waste")
            ->selectRaw("COALESCE(SUM(CASE WHEN m.reason = 'stocktaking' AND m.operation = 'out' THEN m.total_cost ELSE 0 END), 0) as shortage")
            ->selectRaw("COALESCE(SUM(CASE WHEN m.reason = 'stocktaking' AND m.operation = 'in' THEN m.total_cost ELSE 0 END), 0) as surplus")
            ->selectRaw("COALESCE(SUM(CASE WHEN m.reason = 'order' AND m.operation = 'out' THEN m.total_cost WHEN m.reason = 'order_return' AND m.operation = 'in' THEN -m.total_cost ELSE 0 END), 0) as consumption")
            ->selectRaw("COALESCE(SUM(CASE WHEN m.reason = 'stock_issue' AND m.operation = 'out' THEN m.total_cost ELSE 0 END), 0) as issues");
    }

    /**
     * @return array{waste: float, shortage: float, surplus: float, loss: float, consumption: float, rate: float}
     */
    private static function lossFigures(object $row): array
    {
        $waste = (float) $row->waste;
        $shortage = (float) $row->shortage;
        $surplus = (float) $row->surplus;
        $consumption = (float) $row->consumption;
        $loss = $waste + $shortage - $surplus;
        $base = $consumption + (float) $row->issues + $loss;

        return [
            'waste' => round($waste, 2),
            'shortage' => round($shortage, 2),
            'surplus' => round($surplus, 2),
            'loss' => round($loss, 2),
            'consumption' => round($consumption, 2),
            'rate' => $base > 0 ? round($loss * 100 / $base, 2) : 0.0,
        ];
    }

    // ------------------------------------------------------------------
    // Losses and stocktaking
    // ------------------------------------------------------------------

    /**
     * Loss per week (per month for periods over three months).
     *
     * @return list<array{label: string, waste: float, shortage: float, surplus: float, loss: float, consumption: float, rate: float}>
     */
    public function lossTrend(InventoryReportFilters $filters): array
    {
        $daily = $this->lossSums($this->period($filters)
            ->whereIn('m.reason', ['waste', 'stocktaking', 'order', 'order_return', 'stock_issue'])
            ->selectRaw('SUBSTR(m.business_date, 1, 10) as day')
            ->groupByRaw('SUBSTR(m.business_date, 1, 10)'))
            ->get()
            ->keyBy('day');

        $monthly = $filters->days() > 92;
        $rows = [];
        $cursor = $monthly ? $filters->start->startOfMonth() : $filters->start->startOfWeek(CarbonImmutable::SATURDAY);
        while ($cursor->lte($filters->end)) {
            $next = $monthly ? $cursor->addMonth() : $cursor->addWeek();
            $from = $cursor->max($filters->start);
            $to = $next->subDay()->min($filters->end);

            $sum = (object) ['waste' => 0, 'shortage' => 0, 'surplus' => 0, 'consumption' => 0, 'issues' => 0];
            for ($day = $from; $day->lte($to); $day = $day->addDay()) {
                $row = $daily->get($day->toDateString());
                if ($row !== null) {
                    foreach (['waste', 'shortage', 'surplus', 'consumption', 'issues'] as $key) {
                        $sum->{$key} += (float) $row->{$key};
                    }
                }
            }

            $rows[] = [
                'label' => $monthly ? $from->translatedFormat('F Y') : $from->format('d/m').' – '.$to->format('d/m'),
                ...self::lossFigures($sum),
            ];
            $cursor = $next;
        }

        return $rows;
    }

    /**
     * Items that lost the most value in the period. For restaurants the
     * shortage is also shown against the recipe (theoretical) consumption.
     *
     * @return list<array{product_id: int, name: string, unit: ?string, waste_qty: float, shortage_qty: float, waste: float, shortage: float, surplus: float, loss: float, consumption: float, rate: float, against_theory: ?float, share: float}>
     */
    public function topLossItems(InventoryReportFilters $filters, int $limit = 15): array
    {
        $rows = $this->lossSums($this->period($filters)
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->select('m.product_id', 'p.name', 'p.unit')
            ->selectRaw("COALESCE(SUM(CASE WHEN m.reason = 'waste' AND m.operation = 'out' THEN m.quantity ELSE 0 END), 0) as waste_qty")
            ->selectRaw("COALESCE(SUM(CASE WHEN m.reason = 'stocktaking' THEN (CASE WHEN m.operation = 'out' THEN m.quantity ELSE -m.quantity END) ELSE 0 END), 0) as shortage_qty")
            ->groupBy('m.product_id', 'p.name', 'p.unit'))
            ->get()
            ->map(fn ($row) => ['row' => $row, ...self::lossFigures($row)])
            ->filter(fn (array $item) => $item['loss'] > 0.005);

        $total = $rows->sum('loss');
        $restaurant = $this->restaurant();

        return $rows->sortByDesc('loss')->take($limit)->map(function (array $item) use ($total, $restaurant) {
            $row = $item['row'];
            $countNet = $item['shortage'] - $item['surplus'];

            return [
                'product_id' => (int) $row->product_id,
                'name' => (string) $row->name,
                'unit' => $row->unit,
                'waste_qty' => round((float) $row->waste_qty, 3),
                'shortage_qty' => round((float) $row->shortage_qty, 3),
                'waste' => $item['waste'],
                'shortage' => $item['shortage'],
                'surplus' => $item['surplus'],
                'loss' => $item['loss'],
                'consumption' => $item['consumption'],
                'rate' => $item['rate'],
                'against_theory' => $restaurant && $item['consumption'] > 0 ? round($countNet * 100 / $item['consumption'], 2) : null,
                'share' => $total > 0 ? round($item['loss'] * 100 / $total, 1) : 0.0,
            ];
        })->values()->all();
    }

    /**
     * Items short in two counts or more within the period.
     *
     * @return list<array{product_id: int, name: string, counts: int, quantity: float, value: float, last: string}>
     */
    public function repeatedShortages(InventoryReportFilters $filters, int $limit = 20): array
    {
        return $this->period($filters)
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->where('m.reason', MovementReason::STOCKTAKING->value)
            ->where('m.operation', 'out')
            ->select('m.product_id', 'p.name')
            ->selectRaw('COUNT(DISTINCT COALESCE(m.referenceable_id, -m.id)) as counts')
            ->selectRaw('SUM(m.quantity) as quantity, SUM(m.total_cost) as value, MAX(m.business_date) as last')
            ->groupBy('m.product_id', 'p.name')
            ->havingRaw('COUNT(DISTINCT COALESCE(m.referenceable_id, -m.id)) >= 2')
            ->orderByDesc('counts')
            ->orderByDesc('value')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'product_id' => (int) $row->product_id,
                'name' => (string) $row->name,
                'counts' => (int) $row->counts,
                'quantity' => round((float) $row->quantity, 3),
                'value' => round((float) $row->value, 2),
                'last' => substr((string) $row->last, 0, 10),
            ])->all();
    }

    /**
     * Stock on hand at the end of the period that has not sold for
     * SLOW_MOVING_DAYS days (or never sold), biggest value first.
     *
     * @return list<array{product_id: int, name: string, quantity: float, value: float, last_sale: ?string, days: ?int}>
     */
    public function slowMoving(InventoryReportFilters $filters, int $limit = 30): array
    {
        $end = InventoryMovementsReportService::upTo($filters->endDate());
        $signedQty = "CASE WHEN m.operation = 'in' THEN m.quantity ELSE -m.quantity END";
        $signedValue = "CASE WHEN m.operation = 'in' THEN m.total_cost ELSE -m.total_cost END";
        $cutoff = $filters->end->subDays(self::SLOW_MOVING_DAYS)->toDateString();

        return $this->movements->movements($filters)
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->where('m.business_date', '<=', $end)
            ->select('m.product_id', 'p.name')
            ->selectRaw("SUM({$signedQty}) as quantity, SUM({$signedValue}) as value")
            ->selectRaw("MAX(CASE WHEN m.reason = 'order' AND m.operation = 'out' THEN m.business_date END) as last_sale")
            ->groupBy('m.product_id', 'p.name')
            ->havingRaw("SUM({$signedQty}) > 0.0005")
            ->havingRaw("(MAX(CASE WHEN m.reason = 'order' AND m.operation = 'out' THEN m.business_date END) IS NULL OR MAX(CASE WHEN m.reason = 'order' AND m.operation = 'out' THEN m.business_date END) < ?)", [$cutoff])
            ->orderByDesc('value')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'product_id' => (int) $row->product_id,
                'name' => (string) $row->name,
                'quantity' => round((float) $row->quantity, 3),
                'value' => round((float) $row->value, 2),
                'last_sale' => $row->last_sale ? substr((string) $row->last_sale, 0, 10) : null,
                'days' => $row->last_sale ? (int) CarbonImmutable::parse(substr((string) $row->last_sale, 0, 10))->diffInDays($filters->end) : null,
            ])->all();
    }

    // ------------------------------------------------------------------
    // Purchases and cost
    // ------------------------------------------------------------------

    /**
     * Purchases and purchase returns per supplier, from the movements.
     *
     * @return list<array{supplier: string, invoices: int, items: int, purchases: float, returns: float, net: float, share: float}>
     */
    public function suppliers(InventoryReportFilters $filters): array
    {
        $rows = $this->period($filters)
            ->whereIn('m.reason', ['purchase', 'purchase_return'])
            ->leftJoin('purchase_invoices as pi', fn ($join) => $join->on('pi.id', '=', 'm.referenceable_id')->where('m.referenceable_type', (new PurchaseInvoice)->getMorphClass()))
            ->leftJoin('return_purchase_invoices as rpi', fn ($join) => $join->on('rpi.id', '=', 'm.referenceable_id')->where('m.referenceable_type', (new ReturnPurchaseInvoice)->getMorphClass()))
            ->leftJoin('suppliers as s', 's.id', '=', DB::raw('COALESCE(pi.supplier_id, rpi.supplier_id)'))
            ->selectRaw('s.id as supplier_id, s.name as supplier')
            ->selectRaw('COUNT(DISTINCT pi.id) as invoices, COUNT(DISTINCT m.product_id) as items')
            ->selectRaw("COALESCE(SUM(CASE WHEN m.reason = 'purchase' THEN m.total_cost ELSE 0 END), 0) as purchases")
            ->selectRaw("COALESCE(SUM(CASE WHEN m.reason = 'purchase_return' THEN m.total_cost ELSE 0 END), 0) as returns")
            ->groupBy('s.id', 's.name')
            ->get();

        $total = $rows->sum(fn ($row) => (float) $row->purchases);

        return $rows->map(fn ($row) => [
            'supplier' => $row->supplier ?? 'بدون مورد',
            'invoices' => (int) $row->invoices,
            'items' => (int) $row->items,
            'purchases' => round((float) $row->purchases, 2),
            'returns' => round((float) $row->returns, 2),
            'net' => round((float) $row->purchases - (float) $row->returns, 2),
            'share' => $total > 0 ? round((float) $row->purchases * 100 / $total, 1) : 0.0,
        ])->sortByDesc('purchases')->values()->all();
    }

    /**
     * Products whose cost changed in the period: first and last cost, and
     * what the change added to (or saved from) the period's purchases.
     *
     * @return list<array{product_id: int, name: string, changes: int, old_cost: float, new_cost: float, change: ?float, purchased_qty: float, impact: float}>
     */
    public function costChanges(InventoryReportFilters $filters, int $limit = 20): array
    {
        $query = DB::table('product_cost_histories as h')
            ->join('products as p', 'p.id', '=', 'h.product_id')
            ->whereBetween('h.created_at', [$filters->start->startOfDay(), $filters->end->endOfDay()])
            ->select('h.id', 'h.product_id', 'p.name', 'h.old_cost', 'h.new_cost')
            ->orderBy('h.id');
        if ($filters->productId !== null) {
            $query->where('h.product_id', $filters->productId);
        }
        $this->movements->filterCategory($query, $filters, 'h.product_id');

        $histories = $query->get()->groupBy('product_id');
        if ($histories->isEmpty()) {
            return [];
        }

        $purchased = $this->period($filters)
            ->where('m.reason', 'purchase')
            ->whereIn('m.product_id', $histories->keys())
            ->selectRaw('m.product_id, SUM(m.quantity) as quantity')
            ->groupBy('m.product_id')
            ->pluck('quantity', 'product_id');

        return $histories->map(function ($rows, $productId) use ($purchased) {
            $old = (float) $rows->first()->old_cost;
            $new = (float) $rows->last()->new_cost;
            $quantity = (float) ($purchased[$productId] ?? 0);

            return [
                'product_id' => (int) $productId,
                'name' => (string) $rows->first()->name,
                'changes' => $rows->count(),
                'old_cost' => round($old, 2),
                'new_cost' => round($new, 2),
                'change' => $old > 0 ? round(($new - $old) * 100 / $old, 1) : null,
                'purchased_qty' => round($quantity, 3),
                'impact' => round(($new - $old) * $quantity, 2),
            ];
        })
            ->filter(fn (array $row) => abs($row['new_cost'] - $row['old_cost']) > 0.0049)
            ->sortByDesc(fn (array $row) => [abs($row['impact']), abs($row['change'] ?? 0)])
            ->take($limit)
            ->values()
            ->all();
    }

    // ------------------------------------------------------------------
    // Warehouses
    // ------------------------------------------------------------------

    private function allWarehouses(InventoryReportFilters $filters): InventoryReportFilters
    {
        return new InventoryReportFilters($filters->start, $filters->end, $filters->compare, null, $filters->mainCategoryId, $filters->subCategoryId, $filters->productId);
    }

    /**
     * Opening, flows and closing value per warehouse (the warehouse filter
     * is ignored so warehouses can be compared).
     *
     * @return list<array{warehouse_id: int, warehouse: string, opening: float, in: float, out: float, transfer_in: float, transfer_out: float, loss: float, closing: float}>
     */
    public function warehouses(InventoryReportFilters $filters): array
    {
        $all = $this->allWarehouses($filters);
        $start = $filters->startDate();
        $inPeriod = "m.business_date >= '{$start}'";
        $signed = "CASE WHEN m.operation = 'in' THEN m.total_cost ELSE -m.total_cost END";
        $transfer = "m.reason IN ('transfer_in', 'transfer_out')";
        $loss = "m.reason IN ('waste', 'stocktaking')";

        $rows = $this->movements->movements($all)
            ->where('m.business_date', '<=', InventoryMovementsReportService::upTo($filters->endDate()))
            ->selectRaw('m.warehouse_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN m.business_date < '{$start}' THEN {$signed} ELSE 0 END), 0) as opening")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$inPeriod} AND m.operation = 'in' AND NOT ({$transfer}) AND NOT ({$loss}) THEN m.total_cost ELSE 0 END), 0) as in_value")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$inPeriod} AND m.operation = 'out' AND NOT ({$transfer}) AND NOT ({$loss}) THEN m.total_cost ELSE 0 END), 0) as out_value")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$inPeriod} AND m.reason = 'transfer_in' THEN m.total_cost ELSE 0 END), 0) as transfer_in")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$inPeriod} AND m.reason = 'transfer_out' THEN m.total_cost ELSE 0 END), 0) as transfer_out")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$inPeriod} AND {$loss} THEN -({$signed}) ELSE 0 END), 0) as loss")
            ->selectRaw("COALESCE(SUM({$signed}), 0) as closing")
            ->groupBy('m.warehouse_id')
            ->get()
            ->keyBy('warehouse_id');

        $warehouses = Warehouse::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $rows->keys()))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name']);

        return $warehouses->map(function (Warehouse $warehouse) use ($rows) {
            $row = $rows->get($warehouse->id);

            return [
                'warehouse_id' => $warehouse->id,
                'warehouse' => $warehouse->name,
                'opening' => round((float) ($row->opening ?? 0), 2),
                'in' => round((float) ($row->in_value ?? 0), 2),
                'out' => round((float) ($row->out_value ?? 0), 2),
                'transfer_in' => round((float) ($row->transfer_in ?? 0), 2),
                'transfer_out' => round((float) ($row->transfer_out ?? 0), 2),
                'loss' => round((float) ($row->loss ?? 0), 2),
                'closing' => round((float) ($row->closing ?? 0), 2),
            ];
        })->values()->all();
    }

    /**
     * Closed transfers in the period, from warehouse → to warehouse.
     *
     * @return list<array{from: string, to: string, transfers: int, items: int, value: float}>
     */
    public function transferMatrix(InventoryReportFilters $filters): array
    {
        $query = DB::table('stock_transfers as t')
            ->join('stock_transfer_items as i', 'i.stock_transfer_id', '=', 't.id')
            ->whereNotNull('t.closed_at')
            ->whereBetween('t.business_date', [$filters->startDate(), InventoryMovementsReportService::upTo($filters->endDate())]);
        if ($filters->warehouseId !== null) {
            $query->where(fn ($where) => $where->where('t.from_warehouse_id', $filters->warehouseId)->orWhere('t.to_warehouse_id', $filters->warehouseId));
        }
        if ($filters->productId !== null) {
            $query->where('i.product_id', $filters->productId);
        }
        $this->movements->filterCategory($query, $filters, 'i.product_id');

        $rows = $query
            ->selectRaw('t.from_warehouse_id, t.to_warehouse_id, COUNT(DISTINCT t.id) as transfers, COUNT(DISTINCT i.product_id) as items, COALESCE(SUM(i.total_cost), 0) as value')
            ->groupBy('t.from_warehouse_id', 't.to_warehouse_id')
            ->orderByDesc('value')
            ->get();
        $names = Warehouse::query()->pluck('name', 'id');

        return $rows->map(fn ($row) => [
            'from' => (string) ($names[$row->from_warehouse_id] ?? '—'),
            'to' => (string) ($names[$row->to_warehouse_id] ?? '—'),
            'transfers' => (int) $row->transfers,
            'items' => (int) $row->items,
            'value' => round((float) $row->value, 2),
        ])->all();
    }

    /**
     * Closed stock issues in the period grouped by their destination text.
     *
     * @return list<array{destination: string, issues: int, value: float, share: float}>
     */
    public function issueDestinations(InventoryReportFilters $filters): array
    {
        $query = DB::table('stock_issues as s')
            ->join('stock_issue_items as i', 'i.stock_issue_id', '=', 's.id')
            ->whereNotNull('s.closed_at')
            ->whereBetween('s.business_date', [$filters->startDate(), InventoryMovementsReportService::upTo($filters->endDate())]);
        if ($filters->warehouseId !== null) {
            $query->where('s.warehouse_id', $filters->warehouseId);
        }
        if ($filters->productId !== null) {
            $query->where('i.product_id', $filters->productId);
        }
        $this->movements->filterCategory($query, $filters, 'i.product_id');

        $rows = $query
            ->selectRaw("COALESCE(NULLIF(TRIM(s.destination), ''), 'بدون جهة') as destination, COUNT(DISTINCT s.id) as issues, COALESCE(SUM(i.total_cost), 0) as value")
            ->groupByRaw("COALESCE(NULLIF(TRIM(s.destination), ''), 'بدون جهة')")
            ->orderByDesc('value')
            ->get();
        $total = $rows->sum(fn ($row) => (float) $row->value);

        return $rows->map(fn ($row) => [
            'destination' => (string) $row->destination,
            'issues' => (int) $row->issues,
            'value' => round((float) $row->value, 2),
            'share' => $total > 0 ? round((float) $row->value * 100 / $total, 1) : 0.0,
        ])->all();
    }

    // ------------------------------------------------------------------
    // Alerts
    // ------------------------------------------------------------------

    /**
     * Checks that point at stock data to look into. Each check reports how
     * many cases it found (0 = fine) and up to ten of them.
     *
     * @return list<array{key: string, title: string, hint: string, level: string, count: int, rows: list<array<string, mixed>>, more: int}>
     */
    public function alerts(InventoryReportFilters $filters): array
    {
        return [
            $this->negativeBalances($filters),
            $this->zeroCostMovements($filters),
            $this->manualAdjustments($filters),
            $this->largeVariances($filters),
            $this->wasteConcentration($filters),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{key: string, title: string, hint: string, level: string, count: int, rows: list<array<string, mixed>>, more: int}
     */
    private static function alert(string $key, string $title, string $hint, string $level, int $count, array $rows, int $groups = 0): array
    {
        return ['key' => $key, 'title' => $title, 'hint' => $hint, 'level' => $count > 0 ? $level : 'success', 'count' => $count, 'rows' => $rows, 'more' => max(0, $groups - count($rows))];
    }

    private function negativeBalances(InventoryReportFilters $filters): array
    {
        $signedQty = "CASE WHEN m.operation = 'in' THEN m.quantity ELSE -m.quantity END";
        $rows = $this->movements->movements($filters)
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->where('m.business_date', '<=', InventoryMovementsReportService::upTo($filters->endDate()))
            ->select('p.name as product', 'w.name as warehouse')
            ->selectRaw("SUM({$signedQty}) as quantity")
            ->groupBy('m.product_id', 'm.warehouse_id', 'p.name', 'w.name')
            ->havingRaw("SUM({$signedQty}) < -0.0005")
            ->orderByRaw("SUM({$signedQty})")
            ->get();

        return self::alert('negative', 'أرصدة بالسالب', 'صنف خرج منه أكتر من اللي دخل في المخزن ده لحد آخر الفترة. غالبًا فاتورة شراء متسجلتش أو صرف من مخزن غلط.', 'danger', $rows->count(),
            $rows->take(10)->map(fn ($row) => ['الصنف' => $row->product, 'المخزن' => $row->warehouse, 'الرصيد' => number_format((float) $row->quantity, 3)])->all(), $rows->count());
    }

    private function zeroCostMovements(InventoryReportFilters $filters): array
    {
        $rows = $this->period($filters)
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->where('m.quantity', '>', 0)
            ->where('m.total_cost', '<=', 0)
            ->select('p.name as product', 'm.reason')
            ->selectRaw('COUNT(*) as movements, SUM(m.quantity) as quantity')
            ->groupBy('m.product_id', 'p.name', 'm.reason')
            ->orderByDesc('movements')
            ->get();

        return self::alert('zero_cost', 'حركات بتكلفة صفر', 'الحركات دي مش داخلة في القيم. راجع تكلفة الصنف.', 'warning', (int) $rows->sum('movements'),
            $rows->take(10)->map(fn ($row) => [
                'الصنف' => $row->product,
                'الحركة' => MovementReason::tryFrom((string) $row->reason)?->label() ?? (string) $row->reason,
                'عدد الحركات' => (int) $row->movements,
            ])->all(), $rows->count());
    }

    private function manualAdjustments(InventoryReportFilters $filters): array
    {
        $bucket = InventoryMovementsReportService::bucketSql();
        $rows = $this->period($filters)
            ->whereRaw("({$bucket}) IN ('other_in', 'other_out')")
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->selectRaw("COALESCE(u.name, 'غير معروف') as user_name, m.operation")
            ->selectRaw('COUNT(*) as movements, COALESCE(SUM(m.total_cost), 0) as value')
            ->groupBy('u.name', 'm.operation')
            ->orderByDesc('value')
            ->get();

        return self::alert('manual', 'تسويات يدوية', 'حركات من غير مستند (تعديل أو رصيد ابتدائي أو يدوي). المفروض تكون قليلة ومعروف سببها.', 'warning', (int) $rows->sum('movements'),
            $rows->take(10)->map(fn ($row) => [
                'المستخدم' => $row->user_name,
                'الاتجاه' => $row->operation === 'in' ? 'دخول' : 'خروج',
                'عدد الحركات' => (int) $row->movements,
                'القيمة' => number_format((float) $row->value, 2),
            ])->all(), $rows->count());
    }

    private function largeVariances(InventoryReportFilters $filters): array
    {
        $rate = self::LARGE_VARIANCE_RATE;
        $query = DB::table('stocktaking_items as i')
            ->join('stocktakings as s', 's.id', '=', 'i.stocktaking_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->whereNotNull('s.closed_at')
            ->whereBetween('s.business_date', [$filters->startDate(), InventoryMovementsReportService::upTo($filters->endDate())])
            ->where('i.stock_quantity', '>', 0)
            ->whereRaw("ABS(i.real_quantity - i.stock_quantity) * 100.0 / i.stock_quantity >= {$rate}");
        if ($filters->warehouseId !== null) {
            $query->where('s.warehouse_id', $filters->warehouseId);
        }
        if ($filters->productId !== null) {
            $query->where('i.product_id', $filters->productId);
        }
        $this->movements->filterCategory($query, $filters, 'i.product_id');

        $rows = $query
            ->select('s.id as stocktaking_id', 'p.name as product', 'i.stock_quantity', 'i.real_quantity')
            ->selectRaw('ABS(i.real_quantity - i.stock_quantity) * i.price as value')
            ->orderByDesc('value')
            ->get();

        return self::alert('variance', 'فروق جرد كبيرة', "صنف اتعد وطلع مختلف عن رصيد السيستم بـ {$rate}% أو أكتر.", 'danger', $rows->count(),
            $rows->take(10)->map(fn ($row) => [
                'الصنف' => $row->product,
                'الجرد' => '#'.$row->stocktaking_id,
                'url' => ItemLedgerReportService::documentUrl((new Stocktaking)->getMorphClass(), $row->stocktaking_id),
                'السيستم' => number_format((float) $row->stock_quantity, 3),
                'الفعلي' => number_format((float) $row->real_quantity, 3),
                'القيمة' => number_format((float) $row->value, 2),
            ])->all(), $rows->count());
    }

    private function wasteConcentration(InventoryReportFilters $filters): array
    {
        $rows = $this->period($filters)
            ->where('m.reason', 'waste')
            ->where('m.operation', 'out')
            ->selectRaw('m.user_id, COALESCE(SUM(m.total_cost), 0) as value')
            ->selectRaw('COUNT(DISTINCT COALESCE(m.referenceable_id, -m.id)) as documents')
            ->groupBy('m.user_id')
            ->orderByDesc('value')
            ->get();

        $total = $rows->sum(fn ($row) => (float) $row->value);
        $documents = (int) $rows->sum('documents');
        $top = $rows->first();
        $share = ($top !== null && $total > 0) ? round((float) $top->value * 100 / $total, 1) : 0.0;
        $flagged = $top !== null && $rows->count() > 1 && $documents >= 3 && $share >= self::WASTE_CONCENTRATION_RATE;
        $names = User::query()->whereIn('id', $rows->pluck('user_id')->filter())->pluck('name', 'id');

        return self::alert('waste_user', 'هالك متركز عند مستخدم', 'مستخدم واحد مسجل '.self::WASTE_CONCENTRATION_RATE.'% أو أكتر من قيمة الهالك.', 'warning', $flagged ? 1 : 0,
            $flagged ? $rows->take(5)->map(fn ($row) => [
                'المستخدم' => $names[$row->user_id] ?? 'غير معروف',
                'المستندات' => (int) $row->documents,
                'القيمة' => number_format((float) $row->value, 2),
                'النسبة' => number_format($total > 0 ? (float) $row->value * 100 / $total : 0, 1).'%',
            ])->all() : []);
    }
}
