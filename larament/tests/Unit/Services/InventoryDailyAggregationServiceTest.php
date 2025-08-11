<?php

use App\Models\Product;
use App\Models\InventoryItem;
use App\Models\InventoryItemMovement;
use App\Models\InventoryItemMovementDaily;
use App\Services\InventoryDailyAggregationService;
use App\Enums\InventoryMovementOperation;
use App\Enums\MovementReason;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Unit\TestCase;

uses(Tests\Unit\TestCase::class);

describe('InventoryDailyAggregationService', function () {
    beforeEach(function () {
        $this->service = new InventoryDailyAggregationService();
    });

    describe('openDay', function () {
        it('creates daily movement records for all products with current stock', function () {
            // Create products with inventory
            $products = Product::factory(3)->create();

            InventoryItem::create(['product_id' => $products[0]->id, 'quantity' => 100.0]);
            InventoryItem::create(['product_id' => $products[1]->id, 'quantity' => 250.0]);
            InventoryItem::create(['product_id' => $products[2]->id, 'quantity' => 50.0]);

            $testDate = Carbon::today();

            // Call openDay
            $result = $this->service->openDay($testDate);

            expect($result)->toBe(3);

            // Verify records were created
            $dailyRecords = InventoryItemMovementDaily::where('date', $testDate->toDateString())->get();
            expect($dailyRecords)->toHaveCount(3);

            // Verify start quantities are set correctly
            foreach ($products as $index => $product) {
                $dailyRecord = $dailyRecords->where('product_id', $product->id)->first();
                expect($dailyRecord)->not()->toBeNull();
                expect($dailyRecord->start_quantity)->toEqual([100.0, 250.0, 50.0][$index]);
                expect($dailyRecord->incoming_quantity)->toEqual(0.0);
                expect($dailyRecord->sales_quantity)->toEqual(0.0);
                expect($dailyRecord->return_sales_quantity)->toEqual(0.0);
                expect($dailyRecord->return_waste_quantity)->toEqual(0.0);
            }
        });

        it('does not create duplicate records if day is already opened', function () {
            $product = Product::factory()->create();
            InventoryItem::create(['product_id' => $product->id, 'quantity' => 100.0]);

            $testDate = Carbon::today();

            // First call
            $result1 = $this->service->openDay($testDate);
            expect($result1)->toBe(1);

            // Second call should not create duplicates
            $result2 = $this->service->openDay($testDate);
            expect($result2)->toBe(0);

            // Verify only one record exists
            $dailyRecords = InventoryItemMovementDaily::where('date', $testDate->toDateString())->get();
            expect($dailyRecords)->toHaveCount(1);
        });

        it('only creates records for products with inventory', function () {
            // Create products, some with inventory, some without
            $products = Product::factory(3)->create();

            // Only first two products have inventory
            InventoryItem::create(['product_id' => $products[0]->id, 'quantity' => 100.0]);
            InventoryItem::create(['product_id' => $products[1]->id, 'quantity' => 250.0]);
            // Third product has no inventory

            $testDate = Carbon::today();

            $result = $this->service->openDay($testDate);

            expect($result)->toBe(2); // Only 2 records created

            $dailyRecords = InventoryItemMovementDaily::where('date', $testDate->toDateString())->get();
            expect($dailyRecords)->toHaveCount(2);

            // Verify third product has no daily record
            $thirdProductRecord = $dailyRecords->where('product_id', $products[2]->id)->first();
            expect($thirdProductRecord)->toBeNull();
        });
    });

    describe('bulkAggregateWithInsertSelect with upsert', function () {
        it('updates existing records with movement data', function () {
            $product = Product::factory()->create();
            $testDate = Carbon::today();
            $dateString = $testDate->toDateString();

            // Create initial daily record
            $dailyRecord = InventoryItemMovementDaily::create([
                'product_id' => $product->id,
                'date' => $dateString,
                'start_quantity' => 100.0,
                'incoming_quantity' => 0.0,
                'sales_quantity' => 0.0,
                'return_sales_quantity' => 0.0,
                'return_waste_quantity' => 0.0,
            ]);

            $this->service->openDay($testDate);
            // Create some movements
            InventoryItemMovement::create([
                'product_id' => $product->id,
                'operation' => InventoryMovementOperation::IN,
                'quantity' => 50.0,
                'reason' => MovementReason::PURCHASE,
                'created_at' => $testDate,
            ]);

            $this->service->openDay($testDate);
            InventoryItemMovement::create([
                'product_id' => $product->id,
                'operation' => InventoryMovementOperation::OUT,
                'quantity' => 30.0,
                'reason' => MovementReason::ORDER,
                'created_at' => $testDate,
            ]);

            // Call the aggregation method
            $this->service->bulkAggregateWithInsertSelect([$product->id], $testDate);

            $dailyRecords = InventoryItemMovementDaily::
                where('product_id', $product->id)
                ->where('date', $testDate)
                ->get();
            // Verify the record was updated, not duplicated
            expect($dailyRecords)->toHaveCount(1);

            $updatedRecord = $dailyRecords->first();
            expect($updatedRecord->start_quantity)->toEqual(100.0); // Should remain unchanged
            expect($updatedRecord->incoming_quantity)->toEqual(50.0);
            expect($updatedRecord->sales_quantity)->toEqual(30.0);
            expect($updatedRecord->return_sales_quantity)->toEqual(0.0);
            expect($updatedRecord->return_waste_quantity)->toEqual(0.0);
        });

        it('creates new records if they do not exist', function () {
            $product = Product::factory()->create();
            $testDate = Carbon::today();
            $dateString = $testDate->toDateString();

            // Create movements without existing daily record
            InventoryItemMovement::create([
                'product_id' => $product->id,
                'operation' => InventoryMovementOperation::IN,
                'quantity' => 75.0,
                'reason' => MovementReason::PURCHASE,
                'created_at' => $testDate,
            ]);

            // Call the aggregation method
            $this->service->bulkAggregateWithInsertSelect([$product->id], $testDate);

            // Verify a new record was created
            $dailyRecord = InventoryItemMovementDaily::where('product_id', $product->id)
                ->where('date', $dateString)
                ->first();

            expect($dailyRecord)->not()->toBeNull();
            expect($dailyRecord->incoming_quantity)->toEqual(75.0);
            expect($dailyRecord->start_quantity)->toEqual(0.0); // Default value for new records
        });
    });
});
