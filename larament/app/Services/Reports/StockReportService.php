<?php

namespace App\Services\Reports;

use App\Enums\ProductType;
use App\Models\Product;
use App\Services\InventoryDailyAggregationService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class StockReportService
{
    /**
     * Resolve report dates based on user filters and current business day.
     *
     * @return array{start: string, end: string, useLive: bool}
     */
    public static function resolveDates(?string $start = null, ?string $end = null): array
    {
        $business = app(InventoryDailyAggregationService::class)->currentBusinessDate()->toDateString();
        $today = Carbon::today()->toDateString();
        $defaultStart = Carbon::today()->subDays(29)->toDateString();

        $endParsed = $end ? Carbon::parse($end)->toDateString() : $today;
        $endDate = min($endParsed, $business);

        $startParsed = $start ? Carbon::parse($start)->toDateString() : $defaultStart;
        $startDate = min($startParsed, $endDate);

        $useLive = ($endDate >= $business);

        return [
            'start' => $startDate,
            'end' => $endDate,
            'useLive' => $useLive,
        ];
    }

    /**
     * Build the Eloquent query for the Stock Report.
     *
     * @return Builder<Product>
     */
    public static function query(string $start, string $end, bool $useLive, ?int $warehouseId = null): Builder
    {
        $query = Product::query()
            ->whereNot('type', ProductType::Manufactured)
            ->with(['category'])
            ->select([
                'products.id',
                'products.name',
                'products.category_id',
                'products.cost',
            ]);

        if ($warehouseId !== null) {
            $query->selectRaw(
                '(
                    SELECT ii.id
                    FROM inventory_items ii
                    WHERE ii.product_id = products.id
                      AND ii.warehouse_id = ?
                    LIMIT 1
                ) as inventory_item_id',
                [$warehouseId]
            );
        } else {
            $query->selectRaw(
                '(
                    SELECT MIN(ii.id)
                    FROM inventory_items ii
                    WHERE ii.product_id = products.id
                ) as inventory_item_id'
            );
        }

        if ($useLive) {
            if ($warehouseId !== null) {
                $query->selectRaw(
                    'COALESCE((
                        SELECT SUM(ii.quantity)
                        FROM inventory_items ii
                        WHERE ii.product_id = products.id
                          AND ii.warehouse_id = ?
                    ), 0) as actual_remaining_quantity',
                    [$warehouseId]
                );
            } else {
                $query->selectRaw(
                    'COALESCE((
                        SELECT SUM(ii.quantity)
                        FROM inventory_items ii
                        WHERE ii.product_id = products.id
                    ), 0) as actual_remaining_quantity'
                );
            }
        } else {
            if ($warehouseId !== null) {
                $query->selectRaw(
                    'COALESCE((
                        SELECT dm.end_quantity
                        FROM inventory_item_movement_daily dm
                        WHERE dm.product_id = products.id
                          AND dm.warehouse_id = ?
                          AND DATE(dm.date) <= ?
                        ORDER BY DATE(dm.date) DESC, dm.id DESC
                        LIMIT 1
                    ), 0) as actual_remaining_quantity',
                    [$warehouseId, $end]
                );
            } else {
                $query->selectRaw(
                    'COALESCE((
                        SELECT SUM(dm.end_quantity)
                        FROM inventory_item_movement_daily dm
                        WHERE dm.product_id = products.id
                          AND DATE(dm.date) <= ?
                          AND NOT EXISTS (
                              SELECT 1
                              FROM inventory_item_movement_daily dm2
                              WHERE dm2.product_id = dm.product_id
                                AND dm2.warehouse_id = dm.warehouse_id
                                AND DATE(dm2.date) <= ?
                                AND (
                                    DATE(dm2.date) > DATE(dm.date)
                                    OR (DATE(dm2.date) = DATE(dm.date) AND dm2.id > dm.id)
                                )
                          )
                    ), 0) as actual_remaining_quantity',
                    [$end, $end]
                );
            }
        }

        if ($warehouseId !== null) {
            $query->selectRaw(
                'COALESCE(
                    (
                        SELECT dm.start_quantity
                        FROM inventory_item_movement_daily dm
                        WHERE dm.product_id = products.id
                          AND dm.warehouse_id = ?
                          AND DATE(dm.date) BETWEEN ? AND ?
                        ORDER BY DATE(dm.date) ASC, dm.id ASC
                        LIMIT 1
                    ),
                    (
                        SELECT dm.end_quantity
                        FROM inventory_item_movement_daily dm
                        WHERE dm.product_id = products.id
                          AND dm.warehouse_id = ?
                          AND DATE(dm.date) < ?
                        ORDER BY DATE(dm.date) DESC, dm.id DESC
                        LIMIT 1
                    ),
                    0
                ) as start_quantity',
                [$warehouseId, $start, $end, $warehouseId, $start]
            );
        } else {
            $query->selectRaw(
                '(
                    COALESCE((
                        SELECT SUM(dm.start_quantity)
                        FROM inventory_item_movement_daily dm
                        WHERE dm.product_id = products.id
                          AND DATE(dm.date) BETWEEN ? AND ?
                          AND NOT EXISTS (
                              SELECT 1
                              FROM inventory_item_movement_daily dm2
                              WHERE dm2.product_id = dm.product_id
                                AND dm2.warehouse_id = dm.warehouse_id
                                AND DATE(dm2.date) BETWEEN ? AND ?
                                AND (
                                    DATE(dm2.date) < DATE(dm.date)
                                    OR (DATE(dm2.date) = DATE(dm.date) AND dm2.id < dm.id)
                                )
                          )
                    ), 0)
                    +
                    COALESCE((
                        SELECT SUM(dm.end_quantity)
                        FROM inventory_item_movement_daily dm
                        WHERE dm.product_id = products.id
                          AND DATE(dm.date) < ?
                          AND NOT EXISTS (
                              SELECT 1
                              FROM inventory_item_movement_daily dm_in
                              WHERE dm_in.product_id = dm.product_id
                                AND dm_in.warehouse_id = dm.warehouse_id
                                AND DATE(dm_in.date) BETWEEN ? AND ?
                          )
                          AND NOT EXISTS (
                              SELECT 1
                              FROM inventory_item_movement_daily dm2
                              WHERE dm2.product_id = dm.product_id
                                AND dm2.warehouse_id = dm.warehouse_id
                                AND DATE(dm2.date) < ?
                                AND (
                                    DATE(dm2.date) > DATE(dm.date)
                                    OR (DATE(dm2.date) = DATE(dm.date) AND dm2.id > dm.id)
                                )
                          )
                    ), 0)
                ) as start_quantity',
                [$start, $end, $start, $end, $start, $start, $end, $start]
            );
        }

        $query->selectRaw('COALESCE(SUM(dailyMovements.incoming_quantity), 0) as incoming');
        $query->selectRaw('COALESCE(SUM(dailyMovements.sales_quantity), 0) - COALESCE(SUM(dailyMovements.return_sales_quantity), 0) as sales');
        $query->selectRaw('COALESCE(SUM(dailyMovements.return_waste_quantity), 0) as return_waste');
        $query->selectRaw('COALESCE(SUM(dailyMovements.transfer_in_quantity), 0) as transfer_in');
        $query->selectRaw('COALESCE(SUM(dailyMovements.transfer_out_quantity), 0) as transfer_out');
        $query->selectRaw('COALESCE(SUM(dailyMovements.manufacturing_in_quantity), 0) as manufacturing_in');
        $query->selectRaw('COALESCE(SUM(dailyMovements.manufacturing_out_quantity), 0) as manufacturing_out');
        $query->selectRaw('COALESCE(SUM(dailyMovements.issue_quantity), 0) as issue');

        if ($warehouseId !== null) {
            $query->selectRaw(
                '(
                    SELECT dm.created_at
                    FROM inventory_item_movement_daily dm
                    WHERE dm.product_id = products.id
                      AND dm.warehouse_id = ?
                      AND DATE(dm.date) BETWEEN ? AND ?
                    ORDER BY DATE(dm.date) ASC, dm.id ASC
                    LIMIT 1
                ) as start_created_at',
                [$warehouseId, $start, $end]
            );

            $query->selectRaw(
                '(
                    SELECT dm.closed_at
                    FROM inventory_item_movement_daily dm
                    WHERE dm.product_id = products.id
                      AND dm.warehouse_id = ?
                      AND DATE(dm.date) BETWEEN ? AND ?
                    ORDER BY DATE(dm.date) DESC, dm.id DESC
                    LIMIT 1
                ) as last_closed_at',
                [$warehouseId, $start, $end]
            );
        } else {
            $query->selectRaw(
                '(
                    SELECT dm.created_at
                    FROM inventory_item_movement_daily dm
                    WHERE dm.product_id = products.id
                      AND DATE(dm.date) BETWEEN ? AND ?
                    ORDER BY DATE(dm.date) ASC, dm.id ASC
                    LIMIT 1
                ) as start_created_at',
                [$start, $end]
            );

            $query->selectRaw(
                '(
                    SELECT dm.closed_at
                    FROM inventory_item_movement_daily dm
                    WHERE dm.product_id = products.id
                      AND DATE(dm.date) BETWEEN ? AND ?
                    ORDER BY DATE(dm.date) DESC, dm.id DESC
                    LIMIT 1
                ) as last_closed_at',
                [$start, $end]
            );
        }

        $query->leftJoin('inventory_item_movement_daily as dailyMovements', function ($join) use ($start, $end, $warehouseId) {
            $join->on('products.id', '=', 'dailyMovements.product_id')
                ->whereRaw('DATE(dailyMovements.date) BETWEEN ? AND ?', [$start, $end])
                ->when($warehouseId !== null, fn ($q) => $q->where('dailyMovements.warehouse_id', $warehouseId));
        })
            ->groupBy([
                'products.id',
                'products.name',
                'products.category_id',
                'products.cost',
            ]);

        return $query;
    }

    /**
     * Calculate stock metrics from a report record.
     *
     * @return array{total: float, consumed: float, ideal: float, expected: float, actual: float, deviation: float, deviation_value: float, deviation_percentage: ?float}
     */
    public static function calculate(object $record): array
    {
        $start = (float) ($record->start_quantity ?? 0);
        $incoming = (float) ($record->incoming ?? $record->incoming_quantity ?? 0);
        $transferIn = (float) ($record->transfer_in ?? $record->transfer_in_quantity ?? 0);
        $manufacturingIn = (float) ($record->manufacturing_in ?? $record->manufacturing_in_quantity ?? 0);
        $sales = (float) ($record->sales ?? $record->sales_quantity ?? 0);
        $returnWaste = (float) ($record->return_waste ?? $record->return_waste_quantity ?? 0);
        $transferOut = (float) ($record->transfer_out ?? $record->transfer_out_quantity ?? 0);
        $manufacturingOut = (float) ($record->manufacturing_out ?? $record->manufacturing_out_quantity ?? 0);
        $issue = (float) ($record->issue ?? $record->issue_quantity ?? 0);
        $actual = (float) ($record->actual_remaining_quantity ?? $record->end_quantity ?? 0);
        $cost = (float) ($record->cost ?? 0);

        $total = round($start + $incoming + $transferIn + $manufacturingIn, 3);
        $consumed = round($sales + $returnWaste + $transferOut + $manufacturingOut + $issue, 3);
        $ideal = round($total - $consumed, 3);
        $actual = round($actual, 3);

        $deviation = round($actual - $ideal, 3);
        if ($deviation == 0.0) {
            $deviation = 0.0;
        }

        $deviationValue = round($deviation * $cost, 2);
        if ($deviationValue == 0.0) {
            $deviationValue = 0.0;
        }

        $deviationPercentage = null;
        if (abs($ideal) >= 0.0001) {
            $deviationPercentage = round(($deviation / abs($ideal)) * 100, 1);
            if ($deviationPercentage == 0.0) {
                $deviationPercentage = 0.0;
            }
        }

        return [
            'total' => $total,
            'consumed' => $consumed,
            'ideal' => $ideal,
            'expected' => $ideal,
            'actual' => $actual,
            'deviation' => $deviation,
            'deviation_value' => $deviationValue,
            'deviation_percentage' => $deviationPercentage,
        ];
    }
}
