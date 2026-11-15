<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Returns as reports need them. A refund belongs to the shift (and day) that
 * paid it, not to the original order's. Its profit impact is the refund minus
 * the cost of what went back to stock; a damaged line loses the whole refund.
 *
 * A returned line is an item, a custom fee (by amount, quantity 0) or an
 * equipment line. Fee and equipment refunds lose their whole amount (equipment
 * cost was consumed and stays on the order), never move stock, and are left
 * out of the restocked / damaged and quantity figures, which are about items.
 */
class ReturnsAggregates
{
    /**
     * @param  QueryBuilder|array<int|string>  $shiftIds
     * @return array{returnsCount: int, returnsAmount: float, cashRefunds: float, cardRefunds: float, talabatCardRefunds: float, returnsProfitImpact: float, restockedQuantity: float, damagedQuantity: float, damagedAmount: float, itemsRefunds: float, feesRefunds: float, equipmentRefunds: float}
     */
    public function forShifts(QueryBuilder|array $shiftIds): array
    {
        $scope = fn () => $shiftIds instanceof QueryBuilder ? clone $shiftIds : $shiftIds;

        return $this->summary(
            DB::table('order_returns')->whereIn('order_returns.shift_id', $scope()),
        );
    }

    /**
     * Returns made between two dates (by when the refund was paid).
     *
     * @return array{returnsCount: int, returnsAmount: float, cashRefunds: float, cardRefunds: float, talabatCardRefunds: float, returnsProfitImpact: float, restockedQuantity: float, damagedQuantity: float, damagedAmount: float, itemsRefunds: float, feesRefunds: float, equipmentRefunds: float}
     */
    public function forPeriod(?string $startDate, ?string $endDate): array
    {
        return $this->summary($this->periodQuery($startDate, $endDate));
    }

    public function periodQuery(?string $startDate, ?string $endDate, string $column = 'order_returns.created_at'): QueryBuilder
    {
        return DB::table('order_returns')
            ->when($startDate, fn ($query) => $query->where($column, '>=', Carbon::parse($startDate)->startOfDay()))
            ->when($endDate, fn ($query) => $query->where($column, '<=', Carbon::parse($endDate)->endOfDay()));
    }

    /**
     * Returned item lines (not fees or equipment) joined to the original order
     * and its item, for grouping by product.
     */
    public function linesQuery(QueryBuilder $returns): QueryBuilder
    {
        return DB::table('order_return_items as ori')
            ->joinSub((clone $returns)->select('order_returns.*'), 'orr', 'orr.id', '=', 'ori.order_return_id')
            ->join('order_items as roi', 'roi.id', '=', 'ori.order_item_id')
            ->join('orders as ro', 'ro.id', '=', 'orr.order_id');
    }

    /**
     * Every returned line (items, fees and equipment) joined to the original
     * order, for grouping by an order attribute (type, hour, date...). roi is
     * null on fee and equipment lines.
     */
    public function allLinesQuery(QueryBuilder $returns): QueryBuilder
    {
        return DB::table('order_return_items as ori')
            ->joinSub((clone $returns)->select('order_returns.*'), 'orr', 'orr.id', '=', 'ori.order_return_id')
            ->leftJoin('order_items as roi', 'roi.id', '=', 'ori.order_item_id')
            ->join('orders as ro', 'ro.id', '=', 'orr.order_id');
    }

    /** SQL: the line's quantity when it is an item, else 0. */
    public static function itemQuantitySql(string $items = 'ori'): string
    {
        return "CASE WHEN {$items}.order_item_id IS NOT NULL THEN {$items}.quantity ELSE 0 END";
    }

    /**
     * Refunded amount per fee name key, for returns in scope.
     *
     * @return array<string, float>
     */
    public function feeRefundsByName(QueryBuilder $returns): array
    {
        return DB::table('order_return_items as ori')
            ->whereIn('ori.order_return_id', (clone $returns)->select('order_returns.id'))
            ->join('order_fees as rf', 'rf.id', '=', 'ori.order_fee_id')
            ->groupBy('rf.name_key')
            ->selectRaw('rf.name_key as name_key, SUM(ori.refund_amount) as amount')
            ->pluck('amount', 'name_key')
            ->map(fn ($amount): float => round((float) $amount, 2))
            ->all();
    }

    /**
     * Refunded uses and amount per equipment id, for returns in scope.
     *
     * @return array<int, array{uses: int, amount: float}>
     */
    public function equipmentRefundsByEquipment(QueryBuilder $returns, ?string $orderType = null): array
    {
        return DB::table('order_return_items as ori')
            ->whereIn('ori.order_return_id', (clone $returns)->select('order_returns.id'))
            ->join('order_equipment as reqp', 'reqp.id', '=', 'ori.order_equipment_id')
            ->when($orderType, fn ($query, $type) => $query
                ->join('orders as ro', 'ro.id', '=', 'reqp.order_id')
                ->where('ro.type', $type))
            ->whereNotNull('reqp.equipment_id')
            ->groupBy('reqp.equipment_id')
            ->selectRaw('reqp.equipment_id as equipment_id, SUM(ori.quantity) as uses, SUM(ori.refund_amount) as amount')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->equipment_id => ['uses' => (int) $row->uses, 'amount' => round((float) $row->amount, 2)]])
            ->all();
    }

    /** SQL for one line's profit impact: refund − cost of what went back to stock. */
    public static function profitImpactSql(string $items = 'ori', string $orderItems = 'roi'): string
    {
        return "{$items}.refund_amount - CASE WHEN {$items}.restocked = 1 THEN {$items}.quantity * COALESCE({$orderItems}.cost, 0) ELSE 0 END";
    }

    /**
     * Refund amount and profit impact grouped by an expression over the
     * joined lines (e.g. "ro.type" or "DATE(orr.created_at)").
     *
     * @return array<string, array{count: int, amount: float, profit_impact: float, quantity: float}>
     */
    public function groupedBy(QueryBuilder $returns, string $expression): array
    {
        $rows = $this->allLinesQuery($returns)
            ->selectRaw("{$expression} as group_key")
            ->selectRaw('COUNT(DISTINCT orr.id) as returns_count')
            ->selectRaw('COALESCE(SUM(ori.refund_amount), 0) as amount')
            ->selectRaw('COALESCE(SUM('.self::profitImpactSql().'), 0) as profit_impact')
            ->selectRaw('COALESCE(SUM('.self::itemQuantitySql().'), 0) as quantity')
            ->groupByRaw($expression)
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row->group_key] = [
                'count' => (int) $row->returns_count,
                'amount' => round((float) $row->amount, 2),
                'profit_impact' => round((float) $row->profit_impact, 2),
                'quantity' => (float) $row->quantity,
            ];
        }

        return $result;
    }

    /**
     * @return array{returnsCount: int, returnsAmount: float, cashRefunds: float, cardRefunds: float, talabatCardRefunds: float, returnsProfitImpact: float, restockedQuantity: float, damagedQuantity: float, damagedAmount: float, itemsRefunds: float, feesRefunds: float, equipmentRefunds: float}
     */
    private function summary(QueryBuilder $returns): array
    {
        $ids = (clone $returns)->select('order_returns.id');

        $header = (clone $returns)
            ->selectRaw('COUNT(*) as returns_count, COALESCE(SUM(order_returns.total_refund), 0) as amount')
            ->first();

        $methods = DB::table('refunds')
            ->whereIn('order_return_id', clone $ids)
            ->selectRaw("COALESCE(SUM(CASE WHEN method = 'cash' THEN amount ELSE 0 END), 0) as cash")
            ->selectRaw("COALESCE(SUM(CASE WHEN method = 'card' THEN amount ELSE 0 END), 0) as card")
            ->selectRaw("COALESCE(SUM(CASE WHEN method = 'talabat_card' THEN amount ELSE 0 END), 0) as talabat_card")
            ->first();

        $lines = DB::table('order_return_items as ori')
            ->leftJoin('order_items as roi', 'roi.id', '=', 'ori.order_item_id')
            ->whereIn('ori.order_return_id', clone $ids)
            ->selectRaw('COALESCE(SUM('.self::profitImpactSql().'), 0) as profit_impact')
            ->selectRaw('COALESCE(SUM(CASE WHEN ori.order_item_id IS NOT NULL AND ori.restocked = 1 THEN ori.quantity ELSE 0 END), 0) as restocked_qty')
            ->selectRaw('COALESCE(SUM(CASE WHEN ori.order_item_id IS NOT NULL AND ori.restocked = 0 THEN ori.quantity ELSE 0 END), 0) as damaged_qty')
            ->selectRaw('COALESCE(SUM(CASE WHEN ori.order_item_id IS NOT NULL AND ori.restocked = 0 THEN ori.refund_amount ELSE 0 END), 0) as damaged_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN ori.order_item_id IS NOT NULL THEN ori.refund_amount ELSE 0 END), 0) as items_refunds')
            ->selectRaw('COALESCE(SUM(CASE WHEN ori.order_fee_id IS NOT NULL THEN ori.refund_amount ELSE 0 END), 0) as fees_refunds')
            ->selectRaw('COALESCE(SUM(CASE WHEN ori.order_equipment_id IS NOT NULL THEN ori.refund_amount ELSE 0 END), 0) as equipment_refunds')
            ->first();

        return [
            'returnsCount' => (int) ($header->returns_count ?? 0),
            'returnsAmount' => round((float) ($header->amount ?? 0), 2),
            'cashRefunds' => round((float) ($methods->cash ?? 0), 2),
            'cardRefunds' => round((float) ($methods->card ?? 0), 2),
            'talabatCardRefunds' => round((float) ($methods->talabat_card ?? 0), 2),
            'returnsProfitImpact' => round((float) ($lines->profit_impact ?? 0), 2),
            'restockedQuantity' => (float) ($lines->restocked_qty ?? 0),
            'damagedQuantity' => (float) ($lines->damaged_qty ?? 0),
            'damagedAmount' => round((float) ($lines->damaged_amount ?? 0), 2),
            'itemsRefunds' => round((float) ($lines->items_refunds ?? 0), 2),
            'feesRefunds' => round((float) ($lines->fees_refunds ?? 0), 2),
            'equipmentRefunds' => round((float) ($lines->equipment_refunds ?? 0), 2),
        ];
    }
}
