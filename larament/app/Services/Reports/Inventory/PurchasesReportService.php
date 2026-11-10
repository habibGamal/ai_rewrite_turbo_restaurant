<?php

namespace App\Services\Reports\Inventory;

use App\Models\InventoryItemMovement;
use App\Models\PurchaseInvoice;
use Illuminate\Database\Eloquent\Builder;

class PurchasesReportService extends InventoryDocumentReportService
{
    /** @return Builder<InventoryItemMovement> */
    public static function query(string $from, string $to, array $filters = []): Builder
    {
        $query = static::movements(new PurchaseInvoice, 'purchase', 'in', $from, $to, $filters)
            ->leftJoin('suppliers as s', 's.id', '=', 'd.supplier_id')
            ->selectRaw('MIN(m.id) as id, p.id as product_id, p.name as product_name, w.id as warehouse_id, w.name as warehouse_name, s.id as supplier_id, s.name as supplier_name')
            ->selectRaw('SUM(m.quantity) as quantity, SUM(m.total_cost) as value, SUM(m.total_cost) / NULLIF(SUM(m.quantity), 0) as average_price')
            ->groupBy('p.id', 'p.name', 'w.id', 'w.name', 's.id', 's.name');

        return static::movementRows($query);
    }
}
