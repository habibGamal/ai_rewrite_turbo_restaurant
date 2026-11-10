<?php

namespace App\Services\Reports\Inventory;

use App\Models\InventoryItemMovement;
use App\Models\Waste;
use Illuminate\Database\Eloquent\Builder;

class WasteReportService extends InventoryDocumentReportService
{
    /** @return Builder<InventoryItemMovement> */
    public static function query(string $from, string $to, array $filters = []): Builder
    {
        $query = static::movements(new Waste, 'waste', 'out', $from, $to, $filters)
            ->selectRaw('MIN(m.id) as id, p.id as product_id, p.name as product_name, w.id as warehouse_id, w.name as warehouse_name, d.notes as waste_reason')
            ->selectRaw('SUM(m.quantity) as quantity, SUM(m.total_cost) as value')
            ->groupBy('p.id', 'p.name', 'w.id', 'w.name', 'd.notes');

        return static::movementRows($query);
    }
}
