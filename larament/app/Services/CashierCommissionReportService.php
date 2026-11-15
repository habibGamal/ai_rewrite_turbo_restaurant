<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CashierCommissionReportService
{
    /**
     * Get cashier performance data query.
     */
    public function getCashierCommissionQuery(array $shiftIds = [], ?Carbon $startDate = null, ?Carbon $endDate = null): Builder
    {
        // 1. Orders subquery
        $ordersSub = DB::table('orders')
            ->select([
                'user_id',
                DB::raw('COUNT(id) as orders_count'),
                DB::raw('COALESCE(SUM(total), 0) as orders_total_value'),
                DB::raw('COUNT(CASE WHEN discount > 0 THEN 1 END) as orders_with_discount_count'),
                DB::raw('COALESCE(SUM(CASE WHEN discount > 0 THEN total ELSE 0 END), 0) as orders_with_discount_value'),
                DB::raw('COUNT(CASE WHEN discount = 0 OR discount IS NULL THEN 1 END) as orders_without_discount_count'),
                DB::raw('COALESCE(SUM(CASE WHEN discount = 0 OR discount IS NULL THEN total ELSE 0 END), 0) as orders_without_discount_value'),
                DB::raw('COALESCE(SUM(discount), 0) as total_discount_amount'),
            ])
            ->whereNotNull('user_id');

        if (! empty($shiftIds)) {
            $ordersSub->whereIn('shift_id', $shiftIds);
        } elseif ($startDate && $endDate) {
            $ordersSub->whereBetween('created_at', [$startDate, $endDate]);
        }
        $ordersSub->groupBy('user_id');

        // 2. Item changes subquery
        $changesSub = DB::table('order_item_changes')
            ->select([
                'order_item_changes.user_id',
                DB::raw("COALESCE(SUM(CASE WHEN change_type = 'added' OR delta > 0 THEN (CASE WHEN change_type = 'added' THEN new_quantity ELSE delta END) ELSE 0 END), 0) as items_added_count"),
                DB::raw("COALESCE(SUM(CASE WHEN change_type = 'added' OR delta > 0 THEN (CASE WHEN change_type = 'added' THEN new_quantity ELSE delta END) * unit_price ELSE 0 END), 0) as items_added_value"),
                DB::raw("COALESCE(SUM(CASE WHEN change_type = 'removed' OR delta < 0 THEN (CASE WHEN change_type = 'removed' THEN old_quantity ELSE ABS(delta) END) ELSE 0 END), 0) as items_removed_count"),
                DB::raw("COALESCE(SUM(CASE WHEN change_type = 'removed' OR delta < 0 THEN (CASE WHEN change_type = 'removed' THEN old_quantity ELSE ABS(delta) END) * unit_price ELSE 0 END), 0) as items_removed_value"),
            ])
            ->whereNotNull('order_item_changes.user_id');

        if (! empty($shiftIds)) {
            $changesSub->join('orders', 'orders.id', '=', 'order_item_changes.order_id')
                ->whereIn('orders.shift_id', $shiftIds);
        } elseif ($startDate && $endDate) {
            $changesSub->whereBetween('order_item_changes.created_at', [$startDate, $endDate]);
        }
        $changesSub->groupBy('order_item_changes.user_id');

        // Main users query
        return User::query()
            ->leftJoinSub($ordersSub, 'cashier_orders', function ($join) {
                $join->on('users.id', '=', 'cashier_orders.user_id');
            })
            ->leftJoinSub($changesSub, 'cashier_changes', function ($join) {
                $join->on('users.id', '=', 'cashier_changes.user_id');
            })
            ->select([
                'users.id',
                'users.name',
                'users.email',
                DB::raw('COALESCE(cashier_orders.orders_count, 0) as orders_count'),
                DB::raw('COALESCE(cashier_orders.orders_total_value, 0) as orders_total_value'),
                DB::raw('COALESCE(cashier_orders.orders_with_discount_count, 0) as orders_with_discount_count'),
                DB::raw('COALESCE(cashier_orders.orders_with_discount_value, 0) as orders_with_discount_value'),
                DB::raw('COALESCE(cashier_orders.orders_without_discount_count, 0) as orders_without_discount_count'),
                DB::raw('COALESCE(cashier_orders.orders_without_discount_value, 0) as orders_without_discount_value'),
                DB::raw('COALESCE(cashier_orders.total_discount_amount, 0) as total_discount_amount'),
                DB::raw('COALESCE(cashier_changes.items_added_count, 0) as items_added_count'),
                DB::raw('COALESCE(cashier_changes.items_added_value, 0) as items_added_value'),
                DB::raw('COALESCE(cashier_changes.items_removed_count, 0) as items_removed_count'),
                DB::raw('COALESCE(cashier_changes.items_removed_value, 0) as items_removed_value'),
                DB::raw('(COALESCE(cashier_changes.items_added_count, 0) - COALESCE(cashier_changes.items_removed_count, 0)) as net_items_count'),
                DB::raw('(COALESCE(cashier_changes.items_added_value, 0) - COALESCE(cashier_changes.items_removed_value, 0)) as net_items_value'),
            ])
            ->where(function ($query) {
                $query->whereNotNull('cashier_orders.orders_count')
                    ->orWhereNotNull('cashier_changes.items_added_count');
            });
    }

    /**
     * Get summary stats across all cashiers.
     */
    public function getSummaryStats(array $shiftIds = [], ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $query = $this->getCashierCommissionQuery($shiftIds, $startDate, $endDate);
        $records = $query->get();

        return [
            'total_cashiers' => $records->count(),
            'total_orders' => (int) $records->sum('orders_count'),
            'total_orders_value' => (float) $records->sum('orders_total_value'),
            'orders_with_discount_count' => (int) $records->sum('orders_with_discount_count'),
            'orders_with_discount_value' => (float) $records->sum('orders_with_discount_value'),
            'orders_without_discount_count' => (int) $records->sum('orders_without_discount_count'),
            'orders_without_discount_value' => (float) $records->sum('orders_without_discount_value'),
            'total_discount_amount' => (float) $records->sum('total_discount_amount'),
            'total_items_added' => (float) $records->sum('items_added_count'),
            'total_items_added_value' => (float) $records->sum('items_added_value'),
            'total_items_removed' => (float) $records->sum('items_removed_count'),
            'total_items_removed_value' => (float) $records->sum('items_removed_value'),
            'net_items_value' => (float) $records->sum('net_items_value'),
        ];
    }

    /**
     * Get statistics for a specific cashier.
     */
    public function getCashierStats(int $userId, array $shiftIds = [], ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $query = $this->getCashierCommissionQuery($shiftIds, $startDate, $endDate)
            ->where('users.id', $userId);
        $record = $query->first();

        if (! $record) {
            return [
                'orders_count' => 0,
                'orders_total_value' => 0.0,
                'orders_with_discount_count' => 0,
                'orders_with_discount_value' => 0.0,
                'orders_without_discount_count' => 0,
                'orders_without_discount_value' => 0.0,
                'total_discount_amount' => 0.0,
                'items_added_count' => 0.0,
                'items_added_value' => 0.0,
                'items_removed_count' => 0.0,
                'items_removed_value' => 0.0,
                'net_items_count' => 0.0,
                'net_items_value' => 0.0,
            ];
        }

        return [
            'orders_count' => (int) $record->getAttribute('orders_count'),
            'orders_total_value' => (float) $record->getAttribute('orders_total_value'),
            'orders_with_discount_count' => (int) $record->getAttribute('orders_with_discount_count'),
            'orders_with_discount_value' => (float) $record->getAttribute('orders_with_discount_value'),
            'orders_without_discount_count' => (int) $record->getAttribute('orders_without_discount_count'),
            'orders_without_discount_value' => (float) $record->getAttribute('orders_without_discount_value'),
            'total_discount_amount' => (float) $record->getAttribute('total_discount_amount'),
            'items_added_count' => (float) $record->getAttribute('items_added_count'),
            'items_added_value' => (float) $record->getAttribute('items_added_value'),
            'items_removed_count' => (float) $record->getAttribute('items_removed_count'),
            'items_removed_value' => (float) $record->getAttribute('items_removed_value'),
            'net_items_count' => (float) $record->getAttribute('net_items_count'),
            'net_items_value' => (float) $record->getAttribute('net_items_value'),
        ];
    }
}
