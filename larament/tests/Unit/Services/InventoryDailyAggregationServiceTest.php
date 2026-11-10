<?php

use App\Enums\InventoryMovementOperation;
use App\Enums\MovementReason;
use App\Enums\ProductType;
use App\Models\InventoryItem;
use App\Models\InventoryItemMovement;
use App\Models\InventoryItemMovementDaily;
use App\Models\Product;
use App\Services\InventoryDailyAggregationService;
use Carbon\Carbon;

uses(Tests\Unit\TestCase::class);

describe('InventoryDailyAggregationService', function () {
    beforeEach(function () {
        $this->service = new InventoryDailyAggregationService;

        // Clear all data before each test
        InventoryItemMovementDaily::truncate();
        InventoryItemMovement::truncate();
        InventoryItem::truncate();
        Product::truncate();
    });

    describe('dayStatus', function () {
        it('returns null when no open day exists', function () {
            $result = $this->service->dayStatus();
            expect($result)->toBeNull();
        });

        it('returns the opened day date when there is an open day', function () {
            $product = Product::factory()->create();
            $testDate = Carbon::today();

            // Create an open day record
            InventoryItemMovementDaily::create([
                'product_id' => $product->id,
                'date' => $testDate->toDateString(),
                'start_quantity' => 100.0,
                'incoming_quantity' => 0.0,
                'sales_quantity' => 0.0,
                'return_sales_quantity' => 0.0,
                'return_waste_quantity' => 0.0,
                'end_quantity' => 100.0,
                'closed_at' => null, // Open day
            ]);

            $result = $this->service->dayStatus();
            expect($result)->not()->toBeNull();
            expect($result->toDateString())->toBe($testDate->toDateString());
        });

        it('returns null when all days are closed', function () {
            $product = Product::factory()->create();
            $testDate = Carbon::today();

            // Create a closed day record
            InventoryItemMovementDaily::create([
                'product_id' => $product->id,
                'date' => $testDate->toDateString(),
                'start_quantity' => 100.0,
                'incoming_quantity' => 0.0,
                'sales_quantity' => 0.0,
                'return_sales_quantity' => 0.0,
                'return_waste_quantity' => 0.0,
                'end_quantity' => 100.0,
                'closed_at' => now(), // Closed day
            ]);

            $result = $this->service->dayStatus();
            expect($result)->toBeNull();
        });

        it('returns the latest open day when multiple open days exist', function () {
            $product = Product::factory()->create();
            $yesterday = Carbon::yesterday();
            $today = Carbon::today();

            // Create two open day records
            InventoryItemMovementDaily::create([
                'product_id' => $product->id,
                'date' => $yesterday->toDateString(),
                'start_quantity' => 100.0,
                'closed_at' => null,
            ]);

            InventoryItemMovementDaily::create([
                'product_id' => $product->id,
                'date' => $today->toDateString(),
                'start_quantity' => 100.0,
                'closed_at' => null,
            ]);

            $result = $this->service->dayStatus();
            expect($result->toDateString())->toBe($today->toDateString());
        });
    });

    describe('currentBusinessDate', function () {
        it('returns today when no day is open', function () {
            expect($this->service->currentBusinessDate()->toDateString())
                ->toBe(Carbon::today()->toDateString());
        });

        it('returns the open day date when a day dated yesterday is still open', function () {
            $product = Product::factory()->create();
            $yesterday = Carbon::yesterday();

            InventoryItemMovementDaily::create([
                'product_id' => $product->id,
                'date' => $yesterday->toDateString(),
                'start_quantity' => 100.0,
                'incoming_quantity' => 0.0,
                'sales_quantity' => 0.0,
                'return_sales_quantity' => 0.0,
                'return_waste_quantity' => 0.0,
                'end_quantity' => 100.0,
                'closed_at' => null,
            ]);

            expect($this->service->currentBusinessDate()->toDateString())
                ->toBe($yesterday->toDateString());
        });
    });

    describe('openDay', function () {
        it('creates daily movement records for all products with current stock', function () {
            // Create products with inventory
            $products = Product::factory(3)->create();

            InventoryItem::create(['product_id' => $products[0]->id, 'quantity' => 100.0]);
            InventoryItem::create(['product_id' => $products[1]->id, 'quantity' => 250.0]);
            InventoryItem::create(['product_id' => $products[2]->id, 'quantity' => 50.0]);

            // Call openDay (no parameters)
            $result = $this->service->openDay();

            expect($result)->toBe(3);

            // Verify records were created for today
            $dailyRecords = InventoryItemMovementDaily::where('date', Carbon::today())->get();
            expect($dailyRecords)->toHaveCount(3);

            // Verify start quantities are set correctly from current inventory
            foreach ($products as $index => $product) {
                $dailyRecord = $dailyRecords->where('product_id', $product->id)->first();
                expect($dailyRecord)->not()->toBeNull();
                expect($dailyRecord->start_quantity)->toEqual([100.0, 250.0, 50.0][$index]);
                expect($dailyRecord->incoming_quantity)->toEqual(0.0);
                expect($dailyRecord->sales_quantity)->toEqual(0.0);
                expect($dailyRecord->return_sales_quantity)->toEqual(0.0);
                expect($dailyRecord->return_waste_quantity)->toEqual(0.0);
                expect($dailyRecord->end_quantity)->toEqual([100.0, 250.0, 50.0][$index]);
                expect($dailyRecord->closed_at)->toBeNull();
            }
        });

        it('uses end_quantity from last day as start_quantity for new day', function () {
            $product = Product::factory()->create();
            InventoryItem::create(['product_id' => $product->id, 'quantity' => 100.0]);

            $yesterday = Carbon::yesterday();

            // Create a previous day record with different end_quantity
            InventoryItemMovementDaily::create([
                'product_id' => $product->id,
                'date' => $yesterday->toDateString(),
                'start_quantity' => 100.0,
                'incoming_quantity' => 50.0,
                'sales_quantity' => 30.0,
                'return_sales_quantity' => 0.0,
                'return_waste_quantity' => 10.0,
                'end_quantity' => 110.0, // 100 + 50 - 30 - 10
                'closed_at' => now(),
            ]);

            $result = $this->service->openDay();

            expect($result)->toBe(1);

            $todayRecord = InventoryItemMovementDaily::where('date', Carbon::today())->first();
            expect($todayRecord)->not()->toBeNull();
            expect($todayRecord->start_quantity)->toEqual(110.0); // Should use yesterday's end_quantity
            expect($todayRecord->end_quantity)->toEqual(110.0); // Should initialize to start_quantity
        });

        it('reopens closed day by setting closed_at to null', function () {
            $product = Product::factory()->create();
            InventoryItem::create(['product_id' => $product->id, 'quantity' => 100.0]);

            $today = Carbon::today();

            // Create a closed day record for today
            InventoryItemMovementDaily::create([
                'product_id' => $product->id,
                'date' => $today->toDateString(),
                'start_quantity' => 100.0,
                'closed_at' => now(),
            ]);

            $result = $this->service->openDay();

            expect($result)->toBe(1); // Should return existing record count

            $todayRecord = InventoryItemMovementDaily::where('date', $today)->first();
            expect($todayRecord->closed_at)->toBeNull(); // Should be reopened
        });

        it('only creates records for products with inventory', function () {
            // Create products, some with inventory, some without
            $products = Product::factory(3)->create();

            // Only first two products have inventory
            InventoryItem::create(['product_id' => $products[0]->id, 'quantity' => 100.0]);
            InventoryItem::create(['product_id' => $products[1]->id, 'quantity' => 250.0]);
            // Third product has no inventory

            $result = $this->service->openDay();

            expect($result)->toBe(2); // Only 2 records created

            $dailyRecords = InventoryItemMovementDaily::where('date', Carbon::today())->get();
            expect($dailyRecords)->toHaveCount(2);

            // Verify third product has no daily record
            $thirdProductRecord = $dailyRecords->where('product_id', $products[2]->id)->first();
            expect($thirdProductRecord)->toBeNull();
        });
    });

    describe('closeDay', function () {
        it('closes all open day records and sets end_quantity to current inventory', function () {
            $products = Product::factory(2)->create();

            // Create inventory items
            InventoryItem::create(['product_id' => $products[0]->id, 'quantity' => 150.0]);
            InventoryItem::create(['product_id' => $products[1]->id, 'quantity' => 300.0]);

            $today = Carbon::today();

            // Create open day records
            InventoryItemMovementDaily::create([
                'product_id' => $products[0]->id,
                'date' => $today->toDateString(),
                'start_quantity' => 100.0,
                'incoming_quantity' => 80.0,
                'sales_quantity' => 30.0,
                'closed_at' => null,
            ]);

            InventoryItemMovementDaily::create([
                'product_id' => $products[1]->id,
                'date' => $today->toDateString(),
                'start_quantity' => 200.0,
                'incoming_quantity' => 150.0,
                'sales_quantity' => 50.0,
                'closed_at' => null,
            ]);

            $result = $this->service->closeDay();

            expect($result)->toBe(2);

            // Verify all records are closed and end_quantity is set
            $closedRecords = InventoryItemMovementDaily::where('date', $today->toDateString())->get();

            foreach ($closedRecords as $record) {
                expect($record->closed_at)->not()->toBeNull();

                if ($record->product_id === $products[0]->id) {
                    expect($record->end_quantity)->toEqual(150.0); // Current inventory quantity
                } else {
                    expect($record->end_quantity)->toEqual(300.0); // Current inventory quantity
                }
            }
        });

        it('returns 0 when no open day exists', function () {
            $result = $this->service->closeDay();
            expect($result)->toBe(0);
        });

        it('re-aggregates stale movements before closing the day', function () {
            $product = Product::factory()->create();
            InventoryItem::updateOrCreate(['product_id' => $product->id], ['quantity' => 100.0]);

            $this->service->openDay();

            $openDay = InventoryItemMovementDaily::where('product_id', $product->id)
                ->whereNull('closed_at')
                ->first();

            expect($openDay)->not()->toBeNull();
            expect((float) $openDay->incoming_quantity)->toEqual(0.0);
            expect((float) $openDay->sales_quantity)->toEqual(0.0);

            // Insert movements directly, bypassing StockService
            InventoryItemMovement::create([
                'product_id' => $product->id,
                'operation' => InventoryMovementOperation::IN,
                'quantity' => 50.0,
                'reason' => MovementReason::PURCHASE,
                'created_at' => $openDay->created_at->copy()->addMinutes(5),
            ]);

            InventoryItemMovement::create([
                'product_id' => $product->id,
                'operation' => InventoryMovementOperation::OUT,
                'quantity' => 20.0,
                'reason' => MovementReason::ORDER,
                'created_at' => $openDay->created_at->copy()->addMinutes(10),
            ]);

            // Update live inventory to simulate movements effect
            InventoryItem::updateOrCreate(['product_id' => $product->id], ['quantity' => 130.0]);

            $this->service->closeDay();

            $closedDay = InventoryItemMovementDaily::where('product_id', $product->id)->first();

            expect($closedDay)->not()->toBeNull();
            expect($closedDay->closed_at)->not()->toBeNull();
            expect((float) $closedDay->incoming_quantity)->toEqual(50.0);
            expect((float) $closedDay->sales_quantity)->toEqual(20.0);
            expect((float) $closedDay->end_quantity)->toEqual(130.0);
        });
    });

    describe('bulkAggregateWithInsertSelect', function () {
        it('throws exception when no open day exists', function () {
            $product = Product::factory()->create();

            expect(fn () => $this->service->bulkAggregateWithInsertSelect([$product->id]))
                ->toThrow(\Exception::class, 'No open day found. Please open a day first.');
        });

        it('updates existing open day records with movement data', function () {
            $product = Product::factory()->create();
            InventoryItem::create(['product_id' => $product->id, 'quantity' => 100.0]);

            // Open a day first
            $this->service->openDay();

            $openDay = InventoryItemMovementDaily::whereNull('closed_at')->first();

            // Create some movements after the day was opened
            InventoryItemMovement::create([
                'product_id' => $product->id,
                'operation' => InventoryMovementOperation::IN,
                'quantity' => 50.0,
                'reason' => MovementReason::PURCHASE,
                'created_at' => $openDay->created_at->addMinutes(10),
            ]);

            InventoryItemMovement::create([
                'product_id' => $product->id,
                'operation' => InventoryMovementOperation::OUT,
                'quantity' => 30.0,
                'reason' => MovementReason::ORDER,
                'created_at' => $openDay->created_at->addMinutes(20),
            ]);

            InventoryItemMovement::create([
                'product_id' => $product->id,
                'operation' => InventoryMovementOperation::IN,
                'quantity' => 10.0,
                'reason' => MovementReason::ORDER_RETURN,
                'created_at' => $openDay->created_at->addMinutes(30),
            ]);

            // Call the aggregation method
            $this->service->bulkAggregateWithInsertSelect([$product->id]);

            // Verify the record was updated
            $updatedRecord = InventoryItemMovementDaily::where('product_id', $product->id)
                ->whereNull('closed_at')
                ->first();

            expect($updatedRecord->start_quantity)->toEqual(100.0); // Should remain unchanged
            expect($updatedRecord->incoming_quantity)->toEqual(50.0);
            expect($updatedRecord->sales_quantity)->toEqual(30.0);
            expect($updatedRecord->return_sales_quantity)->toEqual(10.0);
            expect($updatedRecord->return_waste_quantity)->toEqual(0.0);
        });

        it('creates records for products without existing daily records', function () {
            $existingProduct = Product::factory()->create();

            InventoryItem::create(['product_id' => $existingProduct->id, 'quantity' => 100.0]);

            // Open day for existing product only (this will create record for existing product)
            $this->service->openDay();

            $newProduct = Product::factory()->create(['type' => ProductType::RawMaterial]);

            $openDay = InventoryItemMovementDaily::whereNull('closed_at')->first();

            // Create movements for the new product
            InventoryItemMovement::create([
                'product_id' => $newProduct->id,
                'operation' => InventoryMovementOperation::IN,
                'quantity' => 25.0,
                'reason' => MovementReason::PURCHASE,
                'created_at' => $openDay->created_at->addMinutes(10),
            ]);

            // Call aggregation for both products
            $this->service->bulkAggregateWithInsertSelect([$existingProduct->id, $newProduct->id]);

            // Verify new record was created for new product
            $newProductRecord = InventoryItemMovementDaily::where('product_id', $newProduct->id)
                ->whereNull('closed_at')
                ->first();

            expect($newProductRecord)->not()->toBeNull();
            expect($newProductRecord->start_quantity)->toEqual(0.0); // New products start with 0
            expect($newProductRecord->incoming_quantity)->toEqual(25.0);
            expect($newProductRecord->date->toDateString())->toBe(Carbon::today()->toDateString());
            expect($newProductRecord->closed_at)->toBeNull();

            // Verify existing product record still exists
            $existingProductRecord = InventoryItemMovementDaily::where('product_id', $existingProduct->id)
                ->whereNull('closed_at')
                ->first();
            expect($existingProductRecord)->not()->toBeNull();
        });

        it('aggregates different movement types correctly', function () {
            $product = Product::factory()->create();
            InventoryItem::create(['product_id' => $product->id, 'quantity' => 100.0]);

            // Open a day
            $this->service->openDay();
            $openDay = InventoryItemMovementDaily::whereNull('closed_at')->first();

            // Create various types of movements
            $movements = [
                ['operation' => InventoryMovementOperation::IN, 'quantity' => 50.0, 'reason' => MovementReason::PURCHASE],
                ['operation' => InventoryMovementOperation::IN, 'quantity' => 20.0, 'reason' => MovementReason::PURCHASE],
                ['operation' => InventoryMovementOperation::OUT, 'quantity' => 30.0, 'reason' => MovementReason::ORDER],
                ['operation' => InventoryMovementOperation::OUT, 'quantity' => 15.0, 'reason' => MovementReason::ORDER],
                ['operation' => InventoryMovementOperation::IN, 'quantity' => 10.0, 'reason' => MovementReason::ORDER_RETURN],
                ['operation' => InventoryMovementOperation::OUT, 'quantity' => 5.0, 'reason' => MovementReason::WASTE],
                ['operation' => InventoryMovementOperation::OUT, 'quantity' => 8.0, 'reason' => MovementReason::PURCHASE_RETURN],
            ];

            foreach ($movements as $index => $movement) {
                InventoryItemMovement::create([
                    'product_id' => $product->id,
                    'operation' => $movement['operation'],
                    'quantity' => $movement['quantity'],
                    'reason' => $movement['reason'],
                    'created_at' => $openDay->created_at->addMinutes(($index + 1) * 5),
                ]);
            }

            $this->service->bulkAggregateWithInsertSelect([$product->id]);

            $record = InventoryItemMovementDaily::where('product_id', $product->id)
                ->whereNull('closed_at')
                ->first();

            expect($record->incoming_quantity)->toEqual(70.0); // 50 + 20
            expect($record->sales_quantity)->toEqual(45.0); // 30 + 15
            expect($record->return_sales_quantity)->toEqual(10.0); // 10
            expect($record->return_waste_quantity)->toEqual(13.0); // 5 + 8
        });

        it('ignores movements before the open day', function () {
            $product = Product::factory()->create();
            InventoryItem::create(['product_id' => $product->id, 'quantity' => 100.0]);

            // Create movements before opening the day
            InventoryItemMovement::insert([
                'product_id' => $product->id,
                'warehouse_id' => \App\Models\Warehouse::default()->id,
                'operation' => InventoryMovementOperation::IN,
                'quantity' => 999.0, // Large amount to detect if included
                'reason' => MovementReason::PURCHASE,
                'created_at' => Carbon::now()->subHour(),
            ]);

            // Open day
            $this->service->openDay();
            $openDay = InventoryItemMovementDaily::whereNull('closed_at')->first();

            // Create movements after opening the day
            InventoryItemMovement::create([
                'product_id' => $product->id,
                'operation' => InventoryMovementOperation::IN,
                'quantity' => 50.0,
                'reason' => MovementReason::PURCHASE,
                'created_at' => $openDay->created_at->addMinutes(10),
            ]);

            $this->service->bulkAggregateWithInsertSelect([$product->id]);

            $record = InventoryItemMovementDaily::where('product_id', $product->id)
                ->whereNull('closed_at')
                ->first();

            expect($record->incoming_quantity)->toEqual(50.0); // Should not include the 999.0
        });
    });

    describe('aggregateMultipleMovements', function () {
        it('calls bulkAggregateWithInsertSelect method', function () {
            $product = Product::factory()->create();
            InventoryItem::create(['product_id' => $product->id, 'quantity' => 100.0]);

            // Open a day first
            $this->service->openDay();

            // This should work without throwing an exception
            $this->service->aggregateMultipleMovements([$product->id], Carbon::today());

            // Verify the record exists (basic test that the method was called)
            $record = InventoryItemMovementDaily::where('product_id', $product->id)
                ->whereNull('closed_at')
                ->first();
            expect($record)->not()->toBeNull();
        });
    });

    describe('per-warehouse daily aggregation', function () {
        it('opens a day with one row per product per warehouse that has stock', function () {
            $main = \App\Models\Warehouse::default();
            $grill = \App\Models\Warehouse::factory()->create(['name' => 'مخزن الشوايا']);

            $product1 = Product::factory()->create();
            $product2 = Product::factory()->create();

            InventoryItem::create(['product_id' => $product1->id, 'warehouse_id' => $main->id, 'quantity' => 30.0]);
            InventoryItem::create(['product_id' => $product1->id, 'warehouse_id' => $grill->id, 'quantity' => 10.0]);
            InventoryItem::create(['product_id' => $product2->id, 'warehouse_id' => $main->id, 'quantity' => 50.0]);

            // Previous closed day for (product1, main) only — should clone end_quantity (25.0), while others use live quantity
            InventoryItemMovementDaily::create([
                'product_id' => $product1->id,
                'warehouse_id' => $main->id,
                'date' => Carbon::yesterday()->toDateString(),
                'start_quantity' => 20.0,
                'end_quantity' => 25.0,
                'closed_at' => now()->subDay(),
            ]);

            $count = $this->service->openDay();

            expect($count)->toBe(3);

            $todayRows = InventoryItemMovementDaily::where('date', Carbon::today())->get();
            expect($todayRows)->toHaveCount(3);

            $p1Main = $todayRows->where('product_id', $product1->id)->where('warehouse_id', $main->id)->first();
            $p1Grill = $todayRows->where('product_id', $product1->id)->where('warehouse_id', $grill->id)->first();
            $p2Main = $todayRows->where('product_id', $product2->id)->where('warehouse_id', $main->id)->first();

            expect($p1Main)->not->toBeNull()
                ->and((float) $p1Main->start_quantity)->toBe(25.0)
                ->and($p1Grill)->not->toBeNull()
                ->and((float) $p1Grill->start_quantity)->toBe(10.0)
                ->and($p2Main)->not->toBeNull()
                ->and((float) $p2Main->start_quantity)->toBe(50.0);

            // Update live quantities and close day: each pair gets its own live end_quantity
            InventoryItem::where('product_id', $product1->id)->where('warehouse_id', $main->id)->update(['quantity' => 22.0]);
            InventoryItem::where('product_id', $product1->id)->where('warehouse_id', $grill->id)->update(['quantity' => 8.5]);

            $this->service->closeDay();

            expect((float) $p1Main->fresh()->end_quantity)->toBe(22.0)
                ->and((float) $p1Grill->fresh()->end_quantity)->toBe(8.5)
                ->and((float) $p2Main->fresh()->end_quantity)->toBe(50.0);
        });

        it('aggregates transfer, manufacturing and issue reasons into their own columns', function () {
            $main = \App\Models\Warehouse::default();
            $grill = \App\Models\Warehouse::factory()->create(['name' => 'مخزن الشوايا']);

            $product = Product::factory()->create();
            InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $main->id, 'quantity' => 100.0]);

            // Open day when product only exists in Main (Grill will get its first movement mid-day)
            $this->service->openDay();
            $openDay = InventoryItemMovementDaily::whereNull('closed_at')->first();
            $afterOpen = $openDay->created_at->copy()->addMinutes(5);

            $mainMovements = [
                ['operation' => InventoryMovementOperation::IN, 'reason' => MovementReason::PURCHASE, 'quantity' => 20.0],
                ['operation' => InventoryMovementOperation::OUT, 'reason' => MovementReason::ORDER, 'quantity' => 5.0],
                ['operation' => InventoryMovementOperation::IN, 'reason' => MovementReason::ORDER_RETURN, 'quantity' => 1.0],
                ['operation' => InventoryMovementOperation::OUT, 'reason' => MovementReason::WASTE, 'quantity' => 2.0],
                ['operation' => InventoryMovementOperation::OUT, 'reason' => MovementReason::PURCHASE_RETURN, 'quantity' => 3.0],
                ['operation' => InventoryMovementOperation::IN, 'reason' => MovementReason::TRANSFER_IN, 'quantity' => 4.0],
                ['operation' => InventoryMovementOperation::OUT, 'reason' => MovementReason::TRANSFER_OUT, 'quantity' => 10.0],
                ['operation' => InventoryMovementOperation::IN, 'reason' => MovementReason::MANUFACTURING_IN, 'quantity' => 15.0],
                ['operation' => InventoryMovementOperation::OUT, 'reason' => MovementReason::MANUFACTURING_OUT, 'quantity' => 8.0],
                ['operation' => InventoryMovementOperation::OUT, 'reason' => MovementReason::STOCK_ISSUE, 'quantity' => 6.0],
            ];

            foreach ($mainMovements as $idx => $m) {
                InventoryItemMovement::create([
                    'product_id' => $product->id,
                    'warehouse_id' => $main->id,
                    'operation' => $m['operation'],
                    'reason' => $m['reason'],
                    'quantity' => $m['quantity'],
                    'created_at' => $afterOpen->copy()->addMinutes($idx),
                ]);
            }

            // First movement in Grill mid-day
            InventoryItemMovement::create([
                'product_id' => $product->id,
                'warehouse_id' => $grill->id,
                'operation' => InventoryMovementOperation::IN,
                'reason' => MovementReason::TRANSFER_IN,
                'quantity' => 10.0,
                'created_at' => $afterOpen->copy()->addMinutes(20),
            ]);

            $this->service->bulkAggregateWithInsertSelect([$product->id]);

            $mainRow = InventoryItemMovementDaily::where('product_id', $product->id)
                ->where('warehouse_id', $main->id)
                ->whereNull('closed_at')
                ->first();

            expect($mainRow)->not->toBeNull()
                ->and((float) $mainRow->start_quantity)->toBe(100.0)
                ->and((float) $mainRow->incoming_quantity)->toBe(20.0)
                ->and((float) $mainRow->sales_quantity)->toBe(5.0)
                ->and((float) $mainRow->return_sales_quantity)->toBe(1.0)
                ->and((float) $mainRow->return_waste_quantity)->toBe(5.0)
                ->and((float) $mainRow->transfer_in_quantity)->toBe(4.0)
                ->and((float) $mainRow->transfer_out_quantity)->toBe(10.0)
                ->and((float) $mainRow->manufacturing_in_quantity)->toBe(15.0)
                ->and((float) $mainRow->manufacturing_out_quantity)->toBe(8.0)
                ->and((float) $mainRow->issue_quantity)->toBe(6.0);

            $grillRow = InventoryItemMovementDaily::where('product_id', $product->id)
                ->where('warehouse_id', $grill->id)
                ->whereNull('closed_at')
                ->first();

            expect($grillRow)->not->toBeNull()
                ->and((float) $grillRow->start_quantity)->toBe(0.0)
                ->and((float) $grillRow->transfer_in_quantity)->toBe(10.0)
                ->and((float) $grillRow->incoming_quantity)->toBe(0.0);
        });

        it('keeps stocktaking out of the aggregated columns', function () {
            $main = \App\Models\Warehouse::default();
            $product = Product::factory()->create();
            InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $main->id, 'quantity' => 50.0]);

            $this->service->openDay();
            $openDay = InventoryItemMovementDaily::whereNull('closed_at')->first();
            $afterOpen = $openDay->created_at->copy()->addMinutes(5);

            $unaggregated = [
                ['operation' => InventoryMovementOperation::OUT, 'reason' => MovementReason::STOCKTAKING, 'quantity' => 3.0],
                ['operation' => InventoryMovementOperation::IN, 'reason' => MovementReason::ADJUSTMENT, 'quantity' => 2.0],
                ['operation' => InventoryMovementOperation::IN, 'reason' => MovementReason::MANUAL, 'quantity' => 4.0],
                ['operation' => InventoryMovementOperation::IN, 'reason' => MovementReason::INITIAL_STOCK, 'quantity' => 10.0],
            ];

            foreach ($unaggregated as $idx => $m) {
                InventoryItemMovement::create([
                    'product_id' => $product->id,
                    'warehouse_id' => $main->id,
                    'operation' => $m['operation'],
                    'reason' => $m['reason'],
                    'quantity' => $m['quantity'],
                    'created_at' => $afterOpen->copy()->addMinutes($idx),
                ]);
            }

            $this->service->bulkAggregateWithInsertSelect([$product->id]);

            $row = InventoryItemMovementDaily::where('product_id', $product->id)
                ->where('warehouse_id', $main->id)
                ->whereNull('closed_at')
                ->first();

            expect((float) $row->incoming_quantity)->toBe(0.0)
                ->and((float) $row->sales_quantity)->toBe(0.0)
                ->and((float) $row->return_sales_quantity)->toBe(0.0)
                ->and((float) $row->return_waste_quantity)->toBe(0.0)
                ->and((float) $row->transfer_in_quantity)->toBe(0.0)
                ->and((float) $row->transfer_out_quantity)->toBe(0.0)
                ->and((float) $row->manufacturing_in_quantity)->toBe(0.0)
                ->and((float) $row->manufacturing_out_quantity)->toBe(0.0)
                ->and((float) $row->issue_quantity)->toBe(0.0);
        });

        it('exposes assertNoOpenDocuments and rejects open stock documents', function () {
            $this->service->assertNoOpenDocuments();

            \App\Models\Waste::factory()->create(['closed_at' => null]);

            expect(fn () => $this->service->assertNoOpenDocuments())
                ->toThrow(\Exception::class, 'لا يمكن إغلاق اليوم لوجود 1 سجل هالك مفتوح');
        });
    });
});
