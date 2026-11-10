<?php

namespace App\Services\Reports\Inventory;

use App\Models\InventoryItemMovement;
use App\Models\StockTransfer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class TransfersReportService extends InventoryDocumentReportService
{
    /** @return Builder<InventoryItemMovement> */
    public static function query(string $from, string $to, array $filters = []): Builder
    {
        $warehouseId = $filters['warehouse_id'] ?? null;
        unset($filters['warehouse_id']);
        $query = static::movements(new StockTransfer, 'transfer_out', 'out', $from, $to, $filters)
            ->join('warehouses as destination', 'destination.id', '=', 'd.to_warehouse_id')
            ->when(filled($warehouseId), fn (QueryBuilder $query) => $query->where(
                fn (QueryBuilder $query) => $query->where('d.from_warehouse_id', $warehouseId)->orWhere('d.to_warehouse_id', $warehouseId)
            ))
            ->selectRaw('MIN(m.id) as id, w.id as from_warehouse_id, w.name as from_warehouse_name, destination.id as to_warehouse_id, destination.name as to_warehouse_name, p.id as product_id, p.name as product_name')
            ->selectRaw('SUM(m.quantity) as quantity, SUM(m.total_cost) as value')
            ->groupBy('w.id', 'w.name', 'destination.id', 'destination.name', 'p.id', 'p.name');

        return static::movementRows($query);
    }
}
