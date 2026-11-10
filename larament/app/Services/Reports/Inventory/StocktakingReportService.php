<?php

namespace App\Services\Reports\Inventory;

use App\Models\Stocktaking;
use App\Models\StocktakingItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class StocktakingReportService extends InventoryDocumentReportService
{
    /** @return Builder<StocktakingItem> */
    public static function query(string $from, string $to, array $filters = []): Builder
    {
        $adjustments = DB::table('inventory_item_movements')
            ->where('referenceable_type', (new Stocktaking)->getMorphClass())->where('reason', 'stocktaking')
            ->selectRaw('referenceable_id, product_id, warehouse_id')
            ->selectRaw("SUM(CASE WHEN operation = 'in' THEN quantity ELSE -quantity END) as quantity")
            ->selectRaw("SUM(CASE WHEN operation = 'in' THEN total_cost ELSE -total_cost END) as value")
            ->groupBy('referenceable_id', 'product_id', 'warehouse_id');

        $query = DB::table('stocktaking_items as i')->join('stocktakings as d', 'd.id', '=', 'i.stocktaking_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->join('warehouses as w', 'w.id', '=', 'd.warehouse_id')
            ->leftJoinSub($adjustments, 'a', fn ($join) => $join->on('a.referenceable_id', '=', 'd.id')->on('a.product_id', '=', 'p.id')->on('a.warehouse_id', '=', 'w.id'))
            ->whereNotNull('d.closed_at')->whereDate('d.business_date', '>=', $from)->whereDate('d.business_date', '<=', $to)
            ->selectRaw('MIN(i.id) as id, d.id as document_id, d.business_date, p.id as product_id, p.name as product_name, w.id as warehouse_id, w.name as warehouse_name')
            ->selectRaw('SUM(i.real_quantity) - COALESCE(a.quantity, 0) as expected_quantity, SUM(i.real_quantity) as actual_quantity, COALESCE(a.quantity, 0) as variance_quantity, COALESCE(a.value, 0) as variance_value')
            ->groupBy('d.id', 'd.business_date', 'p.id', 'p.name', 'w.id', 'w.name', 'a.quantity', 'a.value');
        static::filter($query, $filters, 'd.warehouse_id');

        return StocktakingItem::query()->fromSub($query, 'stocktaking_items')->select('stocktaking_items.*');
    }
}
