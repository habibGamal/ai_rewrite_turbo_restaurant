<?php

namespace App\Services\Reports\Inventory;

use App\Models\InventoryItemMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

abstract class InventoryDocumentReportService
{
    protected static function movements(Model $document, string $reason, string $operation, string $from, string $to, array $filters): QueryBuilder
    {
        $query = DB::table('inventory_item_movements as m')
            ->join($document->getTable().' as d', 'd.id', '=', 'm.referenceable_id')
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->where('m.referenceable_type', $document->getMorphClass())
            ->where('m.reason', $reason)->where('m.operation', $operation)
            ->whereNotNull('d.closed_at')->whereDate('d.business_date', '>=', $from)->whereDate('d.business_date', '<=', $to);

        return static::filter($query, $filters, 'm.warehouse_id');
    }

    protected static function filter(QueryBuilder $query, array $filters, string $warehouseColumn): QueryBuilder
    {
        foreach (['warehouse_id' => $warehouseColumn, 'sub_category_id' => 'p.category_id', 'product_id' => 'p.id'] as $filter => $column) {
            if (filled($filters[$filter] ?? null)) {
                $query->where($column, (int) $filters[$filter]);
            }
        }

        if (filled($filters['main_category_id'] ?? null)) {
            $id = (int) $filters['main_category_id'];
            $query->where(fn (QueryBuilder $query) => $query->where('c.id', $id)->orWhere('c.parent_id', $id));
        }

        return $query;
    }

    /** @return Builder<InventoryItemMovement> */
    protected static function movementRows(QueryBuilder $query): Builder
    {
        // Keep row lookup outside the aggregation so queued exports retain complete groups.
        return InventoryItemMovement::query()->fromSub($query, 'inventory_item_movements')
            ->select('inventory_item_movements.*');
    }
}
