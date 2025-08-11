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
     * Open a day by creating InventoryItemMovementDaily records for all products with current stock
     * Only creates records if they don't already exist for the given date
     */
    public function openDay(Carbon $date): int
    {
        $dateString = $date->toDateString();

        return DB::transaction(function () use ($dateString) {
            try {
                // Get all products that have inventory items but don't have daily movement records for this date
                $insertQuery = DB::table('inventory_items as ii')
                    ->select([
                        'ii.product_id',
                        DB::raw("'{$dateString}' as date"),
                        'ii.quantity as start_quantity',
                        DB::raw('0 as incoming_quantity'),
                        DB::raw('0 as return_sales_quantity'),
                        DB::raw('0 as sales_quantity'),
                        DB::raw('0 as return_waste_quantity'),
                        DB::raw("datetime('now') as created_at"),
                        DB::raw("datetime('now') as updated_at")
                    ])
                    ->leftJoin('inventory_item_movement_daily as imd', function ($join) use ($dateString) {
                        $join->on('ii.product_id', '=', 'imd.product_id')
                            ->where('imd.date', '=', $dateString);
                    })
                    ->whereNull('imd.id'); // Only products without existing daily records

                // Insert only if records don't exist
                $insertedCount = DB::table('inventory_item_movement_daily')
                    ->insertUsing([
                        'product_id',
                        'date',
                        'start_quantity',
                        'incoming_quantity',
                        'return_sales_quantity',
                        'sales_quantity',
                        'return_waste_quantity',
                        'created_at',
                        'updated_at'
                    ], $insertQuery);

                Log::info("Opened day for {$dateString}", [
                    'date' => $dateString,
                    'records_created' => $insertedCount
                ]);

                return $insertedCount;

            } catch (\Exception $e) {
                Log::error("Failed to open day for {$dateString}", [
                    'error' => $e->getMessage(),
                    'date' => $dateString,
                ]);
                throw $e;
            }
        });
    }

    /**
     * Aggregate movements for multiple products and dates using the configured approach
     */
    public function aggregateMultipleMovements(array $productIds, Carbon $date)
    {
        return DB::transaction(function () use ($productIds, $date) {
            $dateString = $date->toDateString();

            try {
                $this->bulkAggregateWithInsertSelect($productIds, $date);

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
     * Pure Laravel Query Builder approach using upsert pattern
     * This updates existing records or inserts new ones
     *
     */
    public function bulkAggregateWithInsertSelect(array $productIds, Carbon $date): void
    {
        // Build the aggregation query
        $aggregationData = DB::table('inventory_item_movements as m')
            ->select([
                'm.product_id',
                DB::raw('DATE(m.created_at) as movement_date'),
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
                ) AS return_sales_quantity"),
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
            ])
            ->whereIn('m.product_id', $productIds)
            ->where(DB::raw('DATE(m.created_at)'), '=', $date->toDateString())
            ->groupBy('m.product_id', DB::raw('DATE(m.created_at)'))
            ->get();

        // Prepare data for upsert
        $upsertData = [];
        foreach ($aggregationData as $row) {
            $upsertData[] = [
                'product_id' => $row->product_id,
                'date' => $row->movement_date,
                'incoming_quantity' => $row->incoming_quantity,
                'return_sales_quantity' => $row->return_sales_quantity,
                'sales_quantity' => $row->sales_quantity,
                'return_waste_quantity' => $row->return_waste_quantity,
                'updated_at' => now(),
            ];
        }

        // dd($upsertData);
        if (!empty($upsertData)) {
            // Handle updates and inserts efficiently with batch operations
            $productIds = array_column($upsertData, 'product_id');

            $existingRecords = InventoryItemMovementDaily::whereIn('product_id', $productIds)
                ->where('date', $date)
                ->get()
                ->keyBy(fn($record) => $record->product_id);

            foreach ($upsertData as $data) {
                $key = $data['product_id'];

                $existing = $existingRecords->get($key);
                $existing->incoming_quantity = $data['incoming_quantity'];
                $existing->return_sales_quantity = $data['return_sales_quantity'];
                $existing->sales_quantity = $data['sales_quantity'];
                $existing->return_waste_quantity = $data['return_waste_quantity'];
                $existing->updated_at = $data['updated_at'];
                $existing->save();
            }
        }
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

}
