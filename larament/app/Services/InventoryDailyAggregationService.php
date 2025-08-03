<?php

namespace App\Services;

use App\Models\InventoryItemMovementDaily;
use App\Models\InventoryItem;
use App\Enums\MovementReason;
use App\Enums\InventoryMovementOperation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InventoryDailyAggregationService
{

    /**
     * Aggregate movements for multiple products and dates using the configured approach
     */
    public function aggregateMultipleMovements(array $productIds, Carbon $date): array
    {
        return DB::transaction(function () use ($productIds, $date) {
            $dateString = $date->toDateString();

            try {
                $this->bulkAggregateWithInsertSelect($productIds, [$dateString]);

            } catch (\Exception $e) {
                Log::error("Failed to bulk aggregate movements for date {$dateString}", [
                    'error' => $e->getMessage(),
                    'product_ids' => $productIds,
                    'date' => $dateString,
                ]);
                throw $e;
            }
        });
    }


    /**
     * Pure Laravel Query Builder approach using INSERT ... SELECT pattern
     * This is the cleanest Laravel way to do bulk aggregation
     */
    public function bulkAggregateWithInsertSelect(array $productIds, array $dateStrings): void
    {
        // Delete existing records first (or you could use REPLACE INTO equivalent)
        InventoryItemMovementDaily::whereIn('product_id', $productIds)
            ->whereIn('date', $dateStrings)
            ->delete();

        // Build the aggregation query
        $aggregationQuery = DB::table('inventory_item_movements as m')
            ->select([
                'm.product_id',
                DB::raw('DATE(m.created_at) as movement_date'),
                DB::raw('COALESCE(prev.start_quantity + prev.incoming_quantity + prev.return_sales_quantity -prev.sales_quantity - prev.return_waste_quantity , 0) as start_quantity'),
                DB::raw("SUM(CASE
                    WHEN m.operation = 'in' AND m.reason IN ('purchase')
                    THEN m.quantity
                    ELSE 0
                END) as incoming_quantity"),
                DB::raw("SUM(
                    CASE
                        WHEN m.operation = 'in' AND m.reason IN ('order_return')
                        THEN m.quantity
                        ELSE 0
                    END
                ) AS sales_return_quantity"),
                DB::raw("SUM(CASE
                    WHEN m.operation = 'out' AND m.reason IN ('order')
                    THEN m.quantity
                    ELSE 0
                END) as sales_quantity"),
                DB::raw("SUM(CASE
                    WHEN (m.operation = 'out' AND m.reason IN ('waste', 'purchase_return'))
                    THEN m.quantity
                    ELSE 0
                END) as return_waste_quantity"),
                DB::raw('NOW() as created_at'),
                DB::raw('NOW() as updated_at')
            ])
            ->leftJoin('inventory_item_movement_daily as prev', function ($join) {
                $join->on('prev.product_id', '=', 'm.product_id')
                    ->on(
                        'prev.date',
                        '=',
                        DB::raw('(
                            SELECT MAX(d.date)
                            FROM inventory_item_movement_daily d
                            WHERE d.product_id = m.product_id
                            AND d.date < DATE(m.created_at)
                        )')
                    );
            })
            ->whereIn('m.product_id', $productIds)
            ->whereIn(DB::raw('DATE(m.created_at)'), $dateStrings)
            ->groupBy('m.product_id', DB::raw('DATE(m.created_at)'));

        // Insert the aggregated data
        DB::table('inventory_item_movement_daily')->insertUsing([
            'product_id',
            'date',
            'start_quantity',
            'incoming_quantity',
            'sales_quantity',
            'return_waste_quantity',
            'created_at',
            'updated_at'
        ], $aggregationQuery);
    }



    /**
     * Get daily summary for a product within a date range
     */
    public function getDailySummary(int $productId, Carbon $startDate, Carbon $endDate): array
    {
        return InventoryItemMovementDaily::forProduct($productId)
            ->forDateRange($startDate, $endDate)
            ->orderBy('date')
            ->get()
            ->toArray();
    }

    /**
     * Recalculate daily aggregations for a date range using super bulk operations
     * Useful for fixing inconsistencies or when historical data needs to be recalculated
     */
    public function recalculateDateRange(Carbon $startDate, Carbon $endDate, array $productIds = null): int
    {
        // Get all products that have movements in the date range
        $query = DB::table('inventory_item_movements')
            ->select('product_id')
            ->whereBetween(DB::raw('DATE(created_at)'), [$startDate->toDateString(), $endDate->toDateString()])
            ->distinct();

        if ($productIds) {
            $query->whereIn('product_id', $productIds);
        }

        $products = $query->pluck('product_id')->unique()->toArray();

        if (empty($products)) {
            return 0;
        }

        // Generate all dates in the range
        $dates = [];
        $currentDate = $startDate->copy();
        while ($currentDate->lte($endDate)) {
            $dates[] = $currentDate->toDateString();
            $currentDate->addDay();
        }

        try {
            // Use the super efficient bulk processing
            $processedCount = $this->aggregateMultipleDates($products, $dates);

            Log::info("Bulk recalculated daily aggregations for date range", [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'products_count' => count($products),
                'days_count' => count($dates),
                'total_records' => $processedCount
            ]);

            return $processedCount;

        } catch (\Exception $e) {
            Log::error("Failed to bulk recalculate daily aggregation for date range", [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'products_count' => count($products),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

}
