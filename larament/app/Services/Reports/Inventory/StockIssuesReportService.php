<?php

namespace App\Services\Reports\Inventory;

use App\Models\InventoryItemMovement;
use App\Models\StockIssue;
use Illuminate\Database\Eloquent\Builder;

class StockIssuesReportService extends InventoryDocumentReportService
{
    /** @return Builder<InventoryItemMovement> */
    public static function query(string $from, string $to, array $filters = []): Builder
    {
        $query = static::movements(new StockIssue, 'stock_issue', 'out', $from, $to, $filters)
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->selectRaw('MIN(m.id) as id, d.destination, w.id as warehouse_id, w.name as warehouse_name, u.id as user_id, u.name as user_name')
            ->selectRaw('SUM(m.quantity) as quantity, SUM(m.total_cost) as value')
            ->groupBy('d.destination', 'w.id', 'w.name', 'u.id', 'u.name');

        return static::movementRows($query);
    }
}
