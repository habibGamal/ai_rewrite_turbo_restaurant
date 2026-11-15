<?php

namespace App\Modules\Manufacturing\Services;

use App\Services\Reports\Inventory\InventoryDocumentReportService;

use App\Models\ManufacturingOrderOutput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class ManufacturingYieldReportService extends InventoryDocumentReportService
{
    /** @return Builder<ManufacturingOrderOutput> */
    public static function query(string $from, string $to, array $filters = []): Builder
    {
        $orders = self::outputs($from, $to, $filters)
            ->selectRaw('MIN(o.id) as id, d.id as document_id, p.id as product_id, p.name as product_name, w.id as warehouse_id, w.name as warehouse_name')
            ->selectRaw('SUM(o.expected_quantity) as expected_quantity, SUM(o.actual_quantity) as actual_quantity, SUM(o.variance_quantity) as variance_quantity, SUM(o.variance_value) as variance_value')
            ->groupBy('d.id', 'p.id', 'p.name', 'w.id', 'w.name');

        $summary = DB::query()->fromSub($orders, 'orders')
            ->selectRaw('MIN(id) as id, product_id, product_name, warehouse_id, warehouse_name, COUNT(*) as order_count')
            ->selectRaw('SUM(expected_quantity) as expected_quantity, SUM(actual_quantity) as actual_quantity, SUM(variance_quantity) as variance_quantity, SUM(variance_value) as variance_value')
            ->selectRaw('100.0 * (SUM(actual_quantity) - SUM(expected_quantity)) / NULLIF(SUM(expected_quantity), 0) as variance_percentage')
            ->selectRaw('AVG(100.0 * (actual_quantity - expected_quantity) / NULLIF(expected_quantity, 0)) as average_variance_percentage')
            ->groupBy('product_id', 'product_name', 'warehouse_id', 'warehouse_name');

        return ManufacturingOrderOutput::query()->fromSub($summary, 'manufacturing_order_outputs')->select('manufacturing_order_outputs.*');
    }

    /** @return Builder<ManufacturingOrderOutput> */
    public static function detailsQuery(string $from, string $to, array $filters = []): Builder
    {
        $query = self::outputs($from, $to, $filters)
            ->join('warehouses as source', 'source.id', '=', 'd.source_warehouse_id')
            ->select('o.*', 'd.id as document_id', 'd.business_date', 'p.name as product_name', 'w.name as warehouse_name', 'source.name as source_warehouse_name')
            ->selectRaw('100.0 * (o.actual_quantity - o.expected_quantity) / NULLIF(o.expected_quantity, 0) as variance_percentage');

        return ManufacturingOrderOutput::query()->fromSub($query, 'manufacturing_order_outputs')
            ->select('manufacturing_order_outputs.*')->with('order.inputs.product');
    }

    private static function outputs(string $from, string $to, array $filters): QueryBuilder
    {
        $query = DB::table('manufacturing_order_outputs as o')
            ->join('manufacturing_orders as d', 'd.id', '=', 'o.manufacturing_order_id')
            ->join('products as p', 'p.id', '=', 'o.product_id')->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->join('warehouses as w', 'w.id', '=', 'd.destination_warehouse_id')
            ->whereNotNull('d.closed_at')->whereDate('d.business_date', '>=', $from)->whereDate('d.business_date', '<=', $to);

        return static::filter($query, $filters, 'd.destination_warehouse_id');
    }
}
