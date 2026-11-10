<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryItemMovementDaily;
use App\Models\ManufacturingOrder;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\ReturnPurchaseInvoice;
use App\Models\Stocktaking;
use App\Models\Warehouse;
use App\Models\Waste;
use App\Sync\DateDocument;
use App\Sync\DocumentType;
use App\Sync\Outbox;
use App\Sync\Payload;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InventoryDailyAggregationService
{
    /**
     * Get the status of the current day
     * Returns the opened day date or null if all days are closed
     */
    public function dayStatus(): ?Carbon
    {
        $openDay = InventoryItemMovementDaily::whereNull('closed_at')
            ->orderBy('date', 'desc')
            ->first();

        return $openDay ? Carbon::parse($openDay->date) : null;
    }

    /**
     * Get the current business date.
     *
     * Returns the open day's date when a day is open, otherwise Carbon::today().
     * This is the date reports should treat as "today" (a day opened before midnight
     * keeps its date even after midnight).
     */
    public function currentBusinessDate(): Carbon
    {
        return $this->dayStatus() ?? Carbon::today();
    }

    /**
     * Open a new day by creating InventoryItemMovementDaily records for all products
     * Clones the last day's end_quantity as start_quantity for today
     */
    public function openDay(): int
    {
        $today = Carbon::today();
        $dateString = $today->toDateString();

        return DB::transaction(function () use ($dateString, $today) {
            try {
                // Check if day is already opened
                $existingRecords = InventoryItemMovementDaily::where('date', $today)->count();
                if ($existingRecords > 0) {
                    // ensure that they not closed by closed_at
                    InventoryItemMovementDaily::where('date', $today)->update(['closed_at' => null]);
                    Log::info("Day {$dateString} is already opened");

                    return $existingRecords;
                }

                // Get all (product, warehouse) inventory item rows
                $inventoryItems = InventoryItem::whereIn('product_id', Product::select('id'))
                    ->orderBy('id')
                    ->get();

                $insertedCount = 0;

                foreach ($inventoryItems as $inventoryItem) {
                    // Get the last daily movement record for this (product, warehouse) pair
                    $lastRecord = InventoryItemMovementDaily::where('product_id', $inventoryItem->product_id)
                        ->where('warehouse_id', $inventoryItem->warehouse_id)
                        ->orderBy('date', 'desc')
                        ->first();

                    $liveQuantity = $inventoryItem->quantity;

                    $startQuantity = 0;
                    if ($lastRecord) {
                        // Use end_quantity from last record
                        $startQuantity = $lastRecord->end_quantity;

                        if ((float) $lastRecord->end_quantity != (float) $liveQuantity) {
                            Log::warning("Product {$inventoryItem->product_id} live inventory differs from last day's end quantity", [
                                'product_id' => $inventoryItem->product_id,
                                'warehouse_id' => $inventoryItem->warehouse_id,
                                'last_end_quantity' => $lastRecord->end_quantity,
                                'live_quantity' => $liveQuantity,
                            ]);
                        }
                    } else {
                        // If no previous record, use current inventory quantity
                        $startQuantity = $liveQuantity;
                    }

                    InventoryItemMovementDaily::create([
                        'product_id' => $inventoryItem->product_id,
                        'warehouse_id' => $inventoryItem->warehouse_id,
                        'date' => $today,
                        'start_quantity' => $startQuantity,
                        'incoming_quantity' => 0,
                        'return_sales_quantity' => 0,
                        'sales_quantity' => 0,
                        'return_waste_quantity' => 0,
                        'transfer_in_quantity' => 0,
                        'transfer_out_quantity' => 0,
                        'manufacturing_in_quantity' => 0,
                        'manufacturing_out_quantity' => 0,
                        'issue_quantity' => 0,
                        'end_quantity' => $startQuantity,
                        'closed_at' => null,
                    ]);

                    $insertedCount++;
                }

                Log::info("Opened day for {$dateString}", [
                    'date' => $dateString,
                    'records_created' => $insertedCount,
                ]);

                return $insertedCount;

            } catch (Exception $e) {
                Log::error("Failed to open day for {$dateString}", [
                    'error' => $e->getMessage(),
                    'date' => $dateString,
                ]);
                throw $e;
            }
        });
    }

    /**
     * Assert that no stock documents are currently open (draft).
     */
    public function assertNoOpenDocuments(): void
    {
        // Check for open purchase invoices
        $openPurchases = PurchaseInvoice::whereNull('closed_at')->count();
        if ($openPurchases > 0) {
            throw new Exception("لا يمكن إغلاق اليوم لوجود {$openPurchases} فاتورة شراء مفتوحة");
        }

        // Check for open return purchase invoices
        $openReturnPurchases = ReturnPurchaseInvoice::whereNull('closed_at')->count();
        if ($openReturnPurchases > 0) {
            throw new Exception("لا يمكن إغلاق اليوم لوجود {$openReturnPurchases} فاتورة مرتجع شراء مفتوحة");
        }

        // Check for open wastes
        $openWastes = Waste::whereNull('closed_at')->count();
        if ($openWastes > 0) {
            throw new Exception("لا يمكن إغلاق اليوم لوجود {$openWastes} سجل هالك مفتوح");
        }

        $openManufacturingOrders = ManufacturingOrder::whereNull('closed_at')->count();
        if ($openManufacturingOrders > 0) {
            throw new Exception("لا يمكن إغلاق اليوم لوجود {$openManufacturingOrders} أمر تصنيع مفتوح");
        }

        // Check for open stocktaking
        $openStocktaking = Stocktaking::whereNull('closed_at')->count();
        if ($openStocktaking > 0) {
            throw new Exception("لا يمكن إغلاق اليوم لوجود {$openStocktaking} جرد مفتوح");
        }
    }

    /**
     * Close the current day by setting end_quantity and closed_at
     */
    public function closeDay(): int
    {
        if (app(ShiftService::class)->getCurrentShift() !== null) {
            throw new Exception('لا يمكن إغلاق اليوم أثناء وجود شيفت مفتوح');
        }

        $this->assertNoOpenDocuments();

        return DB::transaction(function () {
            try {
                // Get all open day records (where closed_at is null)
                $openRecords = InventoryItemMovementDaily::whereNull('closed_at')->get();

                if ($openRecords->isEmpty()) {
                    Log::info('No open day to close');

                    return 0;
                }

                $firstOpenRecord = $openRecords->sortBy('created_at')->first();
                $movedProductIds = DB::table('inventory_item_movements')
                    ->where('created_at', '>=', $firstOpenRecord->created_at)
                    ->distinct()
                    ->pluck('product_id')
                    ->all();

                $productIds = array_values(array_unique(array_merge(
                    $openRecords->pluck('product_id')->filter()->all(),
                    $movedProductIds
                )));

                if (! empty($productIds)) {
                    $this->bulkAggregateWithInsertSelect($productIds);
                }

                $openRecords = InventoryItemMovementDaily::whereNull('closed_at')->get();

                $updatedCount = 0;
                $currentTime = now();

                foreach ($openRecords as $record) {
                    // Get current inventory quantity for this (product, warehouse) pair
                    $inventoryItem = InventoryItem::where('product_id', $record->product_id)
                        ->where('warehouse_id', $record->warehouse_id)
                        ->first();
                    $currentQuantity = $inventoryItem ? $inventoryItem->quantity : 0;

                    // Update the record with end_quantity and closed_at
                    $record->update([
                        'end_quantity' => $currentQuantity,
                        'closed_at' => $currentTime,
                    ]);

                    $updatedCount++;
                }

                Log::info('Closed day', [
                    'records_updated' => $updatedCount,
                    'closed_at' => $currentTime,
                ]);

                $closedDate = Payload::date($openRecords->first()->date);

                if ($closedDate && PlatformConnection::active()) {
                    $inventoryDayUuid = DateDocument::uuid(DocumentType::InventoryDay, $closedDate);
                    Outbox::record(DocumentType::InventoryDay, $inventoryDayUuid, key: $closedDate);

                    $daySummaryUuid = DateDocument::uuid(DocumentType::DaySummary, $closedDate);
                    Outbox::record(DocumentType::DaySummary, $daySummaryUuid, key: $closedDate);
                }

                return $updatedCount;

            } catch (Exception $e) {
                Log::error('Failed to close day', [
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }

    /**
     * Aggregate movements for specified products using movements since the opened day
     */
    public function bulkAggregateWithInsertSelect(array $productIds): void
    {
        DB::transaction(function () use ($productIds) {
            try {
                // Get the last opened day date
                $openDay = InventoryItemMovementDaily::whereNull('closed_at')
                    ->orderBy('date', 'desc')
                    ->orderBy('created_at', 'asc')
                    ->first();

                if (! $openDay) {
                    throw new Exception('No open day found. Please open a day first.');
                }

                $openDayDate = Carbon::parse($openDay->date);

                // Build the aggregation query for movements since the opened day
                $aggregationData = DB::table('inventory_item_movements as m')
                    ->select([
                        'm.product_id',
                        'm.warehouse_id',
                        DB::raw("SUM(CASE
                            WHEN m.reason IN ('purchase')
                            THEN m.quantity
                            ELSE 0
                        END) as incoming_quantity"),
                        DB::raw("SUM(
                            CASE
                                WHEN m.reason IN ('order_return')
                                THEN m.quantity
                                ELSE 0
                            END
                        ) AS return_sales_quantity"),
                        DB::raw("SUM(CASE
                            WHEN m.reason IN ('order')
                            THEN m.quantity
                            ELSE 0
                        END) as sales_quantity"),
                        DB::raw("SUM(CASE
                            WHEN m.reason IN ('waste', 'purchase_return')
                            THEN m.quantity
                            ELSE 0
                        END) as return_waste_quantity"),
                        DB::raw("SUM(CASE
                            WHEN m.reason IN ('transfer_in')
                            THEN m.quantity
                            ELSE 0
                        END) as transfer_in_quantity"),
                        DB::raw("SUM(CASE
                            WHEN m.reason IN ('transfer_out')
                            THEN m.quantity
                            ELSE 0
                        END) as transfer_out_quantity"),
                        DB::raw("SUM(CASE
                            WHEN m.reason IN ('manufacturing_in')
                            THEN m.quantity
                            ELSE 0
                        END) as manufacturing_in_quantity"),
                        DB::raw("SUM(CASE
                            WHEN m.reason IN ('manufacturing_out')
                            THEN m.quantity
                            ELSE 0
                        END) as manufacturing_out_quantity"),
                        DB::raw("SUM(CASE
                            WHEN m.reason IN ('stock_issue')
                            THEN m.quantity
                            ELSE 0
                        END) as issue_quantity"),
                    ])
                    ->whereIn('m.product_id', $productIds)
                    ->where('m.created_at', '>=', $openDay->created_at)
                    ->groupBy('m.product_id', 'm.warehouse_id')
                    ->get()
                    ->keyBy(fn ($row) => $row->product_id.':'.$row->warehouse_id);

                // Get existing open day records for the specified products
                $existingRecords = InventoryItemMovementDaily::whereIn('product_id', $productIds)
                    ->where('date', $openDayDate)
                    ->whereNull('closed_at')
                    ->get()
                    ->keyBy(fn (InventoryItemMovementDaily $record) => $record->product_id.':'.$record->warehouse_id);

                // Update existing records
                foreach ($existingRecords as $key => $record) {
                    $data = $aggregationData->get($key);
                    if ($data) {
                        $record->update([
                            'incoming_quantity' => $data->incoming_quantity,
                            'return_sales_quantity' => $data->return_sales_quantity,
                            'sales_quantity' => $data->sales_quantity,
                            'return_waste_quantity' => $data->return_waste_quantity,
                            'transfer_in_quantity' => $data->transfer_in_quantity,
                            'transfer_out_quantity' => $data->transfer_out_quantity,
                            'manufacturing_in_quantity' => $data->manufacturing_in_quantity,
                            'manufacturing_out_quantity' => $data->manufacturing_out_quantity,
                            'issue_quantity' => $data->issue_quantity,
                            'updated_at' => now(),
                        ]);
                    }
                }

                $createdRecordsCount = 0;

                // Create records for (product, warehouse) pairs that had movements mid-day without an existing record
                foreach ($aggregationData as $key => $data) {
                    if (! $existingRecords->has($key)) {
                        InventoryItemMovementDaily::create([
                            'product_id' => $data->product_id,
                            'warehouse_id' => $data->warehouse_id,
                            'date' => $openDayDate,
                            'start_quantity' => 0,
                            'incoming_quantity' => $data->incoming_quantity,
                            'return_sales_quantity' => $data->return_sales_quantity,
                            'sales_quantity' => $data->sales_quantity,
                            'return_waste_quantity' => $data->return_waste_quantity,
                            'transfer_in_quantity' => $data->transfer_in_quantity,
                            'transfer_out_quantity' => $data->transfer_out_quantity,
                            'manufacturing_in_quantity' => $data->manufacturing_in_quantity,
                            'manufacturing_out_quantity' => $data->manufacturing_out_quantity,
                            'issue_quantity' => $data->issue_quantity,
                            'end_quantity' => 0,
                            'closed_at' => null,
                        ]);
                        $createdRecordsCount++;
                    }
                }

                // Create records in default warehouse for products that have neither existing records nor movements
                $coveredProductIds = array_unique(array_merge(
                    $existingRecords->pluck('product_id')->all(),
                    $aggregationData->pluck('product_id')->all()
                ));
                $missingProductIds = array_diff($productIds, $coveredProductIds);

                if (! empty($missingProductIds)) {
                    $defaultWarehouseId = Warehouse::default()->id;
                    foreach ($missingProductIds as $productId) {
                        InventoryItemMovementDaily::create([
                            'product_id' => $productId,
                            'warehouse_id' => $defaultWarehouseId,
                            'date' => $openDayDate,
                            'start_quantity' => 0,
                            'incoming_quantity' => 0,
                            'return_sales_quantity' => 0,
                            'sales_quantity' => 0,
                            'return_waste_quantity' => 0,
                            'transfer_in_quantity' => 0,
                            'transfer_out_quantity' => 0,
                            'manufacturing_in_quantity' => 0,
                            'manufacturing_out_quantity' => 0,
                            'issue_quantity' => 0,
                            'end_quantity' => 0,
                            'closed_at' => null,
                        ]);
                        $createdRecordsCount++;
                    }
                }

                Log::info('Bulk aggregated movements', [
                    'product_ids' => $productIds,
                    'open_day_date' => $openDayDate->toDateString(),
                    'updated_records' => $existingRecords->count(),
                    'created_records' => $createdRecordsCount,
                ]);

            } catch (Exception $e) {
                Log::error('Failed to bulk aggregate movements', [
                    'error' => $e->getMessage(),
                    'product_ids' => $productIds,
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
        // Aggregation always targets the open day ($date parameter is unused)
        DB::transaction(callback: function () use ($productIds) {
            try {
                $this->bulkAggregateWithInsertSelect($productIds);

            } catch (Exception $e) {
                Log::error('Failed to bulk aggregate movements', [
                    'error' => $e->getMessage(),
                    'product_ids' => $productIds,
                ]);
                throw $e;
            }
        });
    }
}
