<?php

namespace App\Services\Reports\Inventory;

use App\Models\ManufacturingOrder;
use App\Models\ManufacturingOrderOutput;
use App\Models\ProductCostHistory;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\ReturnPurchaseInvoice;
use App\Models\ReturnPurchaseInvoiceItem;
use App\Models\StockIssue;
use App\Models\Stocktaking;
use App\Models\Waste;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class CostHistoryReportService extends InventoryDocumentReportService
{
    /** @return Builder<ProductCostHistory> */
    public static function query(string $from, string $to, array $filters = []): Builder
    {
        $query = DB::table('product_cost_histories as h')
            ->leftJoinSub(self::sources(), 'd', fn ($join) => $join->on('d.source_type', '=', 'h.source_type')->on('d.source_id', '=', 'h.source_id'))
            ->join('products as p', 'p.id', '=', 'h.product_id')->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'd.warehouse_id')->leftJoin('users as u', 'u.id', '=', 'h.user_id')
            ->where(fn (QueryBuilder $query) => $query->whereNull('h.source_type')->orWhereNotNull('d.closed_at'))
            ->select('h.*', 'p.name as product_name', 'w.name as warehouse_name', 'd.warehouse_id', 'd.document_id', 'd.document_type', 'u.name as user_name')
            ->selectRaw('CASE WHEN h.source_type IS NULL THEN DATE(h.created_at) ELSE DATE(d.business_date) END as report_date');
        static::filter($query, $filters, 'd.warehouse_id');

        return ProductCostHistory::query()->fromSub($query, 'product_cost_histories')
            ->select('product_cost_histories.*')->whereBetween('report_date', [$from, $to]);
    }

    private static function sources(): QueryBuilder
    {
        $sources = null;
        foreach ([
            [PurchaseInvoice::class, 'فاتورة مشتريات', 'warehouse_id', PurchaseInvoiceItem::class, 'purchase_invoice_id'],
            [ManufacturingOrder::class, 'أمر تصنيع', 'destination_warehouse_id', ManufacturingOrderOutput::class, 'manufacturing_order_id'],
            [ReturnPurchaseInvoice::class, 'مرتجع مشتريات', 'warehouse_id', ReturnPurchaseInvoiceItem::class, 'return_purchase_invoice_id'],
            [Stocktaking::class, 'جرد', 'warehouse_id', null, null],
            [Waste::class, 'هالك', 'warehouse_id', null, null],
            [StockIssue::class, 'إذن صرف', 'warehouse_id', null, null],
        ] as [$class, $label, $warehouse, $itemClass, $foreignKey]) {
            $model = new $class;
            $document = DB::table($model->getTable().' as doc')
                ->selectRaw('doc.id as document_id, doc.business_date, doc.closed_at, doc.'.$warehouse.' as warehouse_id')
                ->selectRaw('? as document_type, ? as source_type, doc.id as source_id', [$label, $model->getMorphClass()]);
            $sources = $sources === null ? $document : $sources->unionAll($document);
            if ($itemClass !== null) {
                $item = new $itemClass;
                $sources->unionAll(DB::table($item->getTable().' as item')
                    ->join($model->getTable().' as doc', 'doc.id', '=', 'item.'.$foreignKey)
                    ->selectRaw('doc.id as document_id, doc.business_date, doc.closed_at, doc.'.$warehouse.' as warehouse_id')
                    ->selectRaw('? as document_type, ? as source_type, item.id as source_id', [$label, $item->getMorphClass()]));
            }
        }

        return $sources;
    }
}
