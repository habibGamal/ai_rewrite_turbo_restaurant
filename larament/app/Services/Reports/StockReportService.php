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
    public static function query(string $start, string $end, bool $useLive): Builder
    {
        $query = Product::query()
            ->whereNot('type', ProductType::Manufactured)
            ->with(['category'])
            ->select([
                'products.id',
                'products.name',
                'products.category_id',
                'products.cost',
                'inventory_items.id as inventory_item_id',
            ]);

        if ($useLive) {
            $query->selectRaw('COALESCE(inventory_items.quantity, 0) as actual_remaining_quantity');
        } else {
            $query->selectRaw(
                'COALESCE((
                    SELECT dm.end_quantity
                    FROM inventory_item_movement_daily dm
                    WHERE dm.product_id = products.id
                      AND DATE(dm.date) <= ?
                    ORDER BY DATE(dm.date) DESC, dm.id DESC
                    LIMIT 1
                ), 0) as actual_remaining_quantity',
                [$end]
            );
        }

        $query->selectRaw(
            'COALESCE(
                (
                    SELECT dm.start_quantity
                    FROM inventory_item_movement_daily dm
                    WHERE dm.product_id = products.id
                      AND DATE(dm.date) BETWEEN ? AND ?
                    ORDER BY DATE(dm.date) ASC, dm.id ASC
                    LIMIT 1
                ),
                (
                    SELECT dm.end_quantity
                    FROM inventory_item_movement_daily dm
                    WHERE dm.product_id = products.id
                      AND DATE(dm.date) < ?
                    ORDER BY DATE(dm.date) DESC, dm.id DESC
                    LIMIT 1
                ),
                0
            ) as start_quantity',
            [$start, $end, $start]
        );

        $query->selectRaw('COALESCE(SUM(dailyMovements.incoming_quantity), 0) as incoming');
        $query->selectRaw('COALESCE(SUM(dailyMovements.sales_quantity), 0) - COALESCE(SUM(dailyMovements.return_sales_quantity), 0) as sales');
        $query->selectRaw('COALESCE(SUM(dailyMovements.return_waste_quantity), 0) as return_waste');

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

        $query->leftJoin('inventory_items', 'products.id', '=', 'inventory_items.product_id')
            ->leftJoin('inventory_item_movement_daily as dailyMovements', function ($join) use ($start, $end) {
                $join->on('products.id', '=', 'dailyMovements.product_id')
                    ->whereRaw('DATE(dailyMovements.date) BETWEEN ? AND ?', [$start, $end]);
            })
            ->groupBy([
                'products.id',
                'products.name',
                'products.category_id',
                'products.cost',
                'inventory_items.quantity',
                'inventory_items.id',
            ]);

        return $query;
    }

    /**
     * Calculate stock metrics from a report record.
     *
     * @return array{total: float, consumed: float, ideal: float, actual: float, deviation: float, deviation_value: float, deviation_percentage: ?float}
     */
    public static function calculate(object $record): array
    {
        $start = (float) ($record->start_quantity ?? 0);
        $incoming = (float) ($record->incoming ?? 0);
        $sales = (float) ($record->sales ?? 0);
        $returnWaste = (float) ($record->return_waste ?? 0);
        $actual = (float) ($record->actual_remaining_quantity ?? 0);
        $cost = (float) ($record->cost ?? 0);

        $total = round($start + $incoming, 3);
        $consumed = round($sales + $returnWaste, 3);
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
            'actual' => $actual,
            'deviation' => $deviation,
            'deviation_value' => $deviationValue,
            'deviation_percentage' => $deviationPercentage,
        ];
    }
}
