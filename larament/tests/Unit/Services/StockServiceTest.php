<?php

use App\Enums\InventoryMovementOperation;
use App\Enums\MovementReason;
use App\Exceptions\StockOperationException;
use App\Models\InventoryItem;
use App\Models\InventoryItemMovement;
use App\Models\InventoryItemMovementDaily;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryDailyAggregationService;
use App\Services\StockService;

uses(Tests\Unit\TestCase::class);

describe('StockService', function () {
    beforeEach(function () {
        $this->dailyAggregationService = \Mockery::mock(InventoryDailyAggregationService::class);
        $this->dailyAggregationService->shouldReceive('aggregateMultipleMovements')->andReturn([]);
        $this->stockService = new StockService($this->dailyAggregationService);
        openInventoryDay();
    });

    afterEach(function () {
        \Mockery::close();
    });

    describe('warehouse stock', function () {
        it('keeps order stock wrappers compatible with movement collections', function () {
            $product = Product::factory()->create(['type' => \App\Enums\ProductType::Consumable]);
            $order = \App\Models\Order::factory()->create();
            \App\Models\OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2]);
            $this->stockService->addStock([['product_id' => $product->id, 'quantity' => 10]]);
            $conversion = new \App\Services\Orders\OrderStockConversionService($this->stockService);
            expect($conversion->removeStockForCompletedOrder($order))->toBeTrue()
                ->and($this->stockService->getCurrentStock($product->id))->toBe(8.0)
                ->and($conversion->addStockForCancelledOrder($order))->toBeTrue()
                ->and($this->stockService->getCurrentStock($product->id))->toBe(10.0);
        });
        it('preserves line value when duplicate lines carry different unit costs', function () {
            $product = Product::factory()->create(['cost' => 20]);
            $movement = $this->stockService->addStock([
                ['product_id' => $product->id, 'quantity' => 2],
                ['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 30],
            ])->sole();
            expect((float) $movement->quantity)->toBe(5.0)
                ->and((float) $movement->unit_cost)->toBe(26.0)
                ->and((float) $movement->total_cost)->toBe(130.0);
        });
        it('adds stock to the given warehouse only and returns persisted costed movements', function () {
            $product = Product::factory()->create(['cost' => 20]);
            $warehouse = Warehouse::factory()->create();
            $user = User::factory()->create();
            $this->actingAs($user);
            $movements = $this->stockService->addStock([
                ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 10.125, 'unit_cost' => 23.4567],
            ], MovementReason::PURCHASE, $product);

            expect($movements)->toBeInstanceOf(\Illuminate\Support\Collection::class)->toHaveCount(1);
            $movement = $movements->sole();
            expect($movement->exists)->toBeTrue()->and($movement->id)->not->toBeNull()
                ->and($movement->warehouse->id)->toBe($warehouse->id)
                ->and($movement->user->id)->toBe($user->id)
                ->and((float) $movement->unit_cost)->toBe(23.4567)
                ->and((float) $movement->total_cost)->toBe(237.50)
                ->and($movement->referenceable->is($product))->toBeTrue()
                ->and($this->stockService->getCurrentStock($product->id, $warehouse->id))->toBe(10.125)
                ->and($this->stockService->getCurrentStock($product->id, Warehouse::default()->id))->toBe(0.0);
        });

        it('defaults to the default warehouse and product cost when none is given', function () {
            auth()->logout();
            $product = Product::factory()->create(['cost' => 20]);
            $movement = $this->stockService->addStock([['product_id' => $product->id, 'quantity' => 3]])->sole();
            expect($movement->warehouse_id)->toBe(Warehouse::default()->id)
                ->and((float) $movement->unit_cost)->toBe(20.0)
                ->and((float) $movement->total_cost)->toBe(60.0)
                ->and($movement->user_id)->toBeNull();
        });

        it('lets one warehouse go negative and sums all warehouses when none is passed', function () {
            $product = Product::factory()->create(['cost' => 20]);
            $warehouse = Warehouse::factory()->create();
            $this->stockService->addStock([['product_id' => $product->id, 'quantity' => 30]]);
            $movement = $this->stockService->removeStock([
                ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 2],
            ])->sole();
            expect($this->stockService->getCurrentStock($product->id, $warehouse->id))->toBe(-2.0)
                ->and($this->stockService->getCurrentStock($product->id))->toBe(28.0)
                ->and((float) $movement->total_cost)->toBe(40.0);
        });

        it('merges duplicate lines per product and warehouse and keeps different warehouses separate', function () {
            $product = Product::factory()->create(['cost' => 20]);
            $warehouse = Warehouse::factory()->create();
            $movements = $this->stockService->addStock([
                ['product_id' => $product->id, 'quantity' => 2],
                ['product_id' => $product->id, 'warehouse_id' => Warehouse::default()->id, 'quantity' => 3],
                ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 4],
            ]);
            expect($movements)->toHaveCount(2)
                ->and((float) $movements->firstWhere('warehouse_id', Warehouse::default()->id)->quantity)->toBe(5.0)
                ->and($this->stockService->getCurrentStock($product->id, $warehouse->id))->toBe(4.0);
        });

        it('keeps an explicit zero incoming cost and values outgoing lines at current cost', function () {
            $product = Product::factory()->create(['cost' => 20]);
            $movement = $this->stockService->addStock([['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 0]])->sole();
            expect((float) $movement->unit_cost)->toBe(0.0)->and((float) $movement->total_cost)->toBe(0.0);
            $out = $this->stockService->removeStock([['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 15.25]])->sole();
            expect((float) $out->unit_cost)->toBe(20.0)->and((float) $out->total_cost)->toBe(40.0);
        });

        it('rolls back all warehouse balances and movements when a warehouse does not exist', function () {
            $product = Product::factory()->create();
            expect(fn () => $this->stockService->addStock([
                ['product_id' => $product->id, 'quantity' => 3],
                ['product_id' => $product->id, 'warehouse_id' => 999999, 'quantity' => 4],
            ]))->toThrow(StockOperationException::class);
            expect($this->stockService->getCurrentStock($product->id))->toBe(0.0)
                ->and(InventoryItemMovement::where('product_id', $product->id)->count())->toBe(0);
        });
    });

    describe('addStock', function () {
        it('can add stock for multiple items', function () {
            $products = Product::factory(3)->create();
            $items = [
                ['product_id' => $products[0]->id, 'quantity' => 50.0],
                ['product_id' => $products[1]->id, 'quantity' => 75.0],
                ['product_id' => $products[2]->id, 'quantity' => 100.0],
            ];

            $result = $this->stockService->addStock($items, MovementReason::PURCHASE);

            expect($result)->toBeInstanceOf(\Illuminate\Support\Collection::class)->not->toBeEmpty();
            foreach ($items as $item) {
                $this->assertDatabaseHas('inventory_items', [
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                ]);
            }
        });

        it('can add stock to existing inventory items', function () {
            $products = Product::factory(2)->create();

            // Create initial inventory
            InventoryItem::create(['product_id' => $products[0]->id, 'quantity' => 50.0]);
            InventoryItem::create(['product_id' => $products[1]->id, 'quantity' => 30.0]);

            $items = [
                ['product_id' => $products[0]->id, 'quantity' => 25.0],
                ['product_id' => $products[1]->id, 'quantity' => 20.0],
            ];

            $result = $this->stockService->addStock($items, MovementReason::PURCHASE);

            expect($result)->toBeInstanceOf(\Illuminate\Support\Collection::class)->not->toBeEmpty();
            $this->assertDatabaseHas('inventory_items', [
                'product_id' => $products[0]->id,
                'quantity' => 75.0, // 50 + 25
            ]);
            $this->assertDatabaseHas('inventory_items', [
                'product_id' => $products[1]->id,
                'quantity' => 50.0, // 30 + 20
            ]);
        });

        it('fails to add stock when products dont exist', function () {
            $items = [
                ['product_id' => 99999, 'quantity' => 50.0],
                ['product_id' => 99998, 'quantity' => 75.0],
            ];

            expect(fn () => $this->stockService->addStock($items, MovementReason::PURCHASE))
                ->toThrow(StockOperationException::class);

            $this->assertDatabaseMissing('inventory_items', ['product_id' => 99999]);
            $this->assertDatabaseMissing('inventory_items', ['product_id' => 99998]);
        });
    });

    describe('removeStock', function () {
        it('can remove stock for multiple items with sufficient inventory', function () {
            $products = Product::factory(3)->create();

            // Create initial inventory
            foreach ($products as $product) {
                InventoryItem::create([
                    'product_id' => $product->id,
                    'quantity' => 100.0,
                ]);
            }

            $items = [
                ['product_id' => $products[0]->id, 'quantity' => 20.0],
                ['product_id' => $products[1]->id, 'quantity' => 30.0],
                ['product_id' => $products[2]->id, 'quantity' => 40.0],
            ];

            $result = $this->stockService->removeStock($items, MovementReason::ORDER);

            expect($result)->toBeInstanceOf(\Illuminate\Support\Collection::class)->not->toBeEmpty();
            foreach ($items as $item) {
                $this->assertDatabaseHas('inventory_items', [
                    'product_id' => $item['product_id'],
                    'quantity' => 100.0 - $item['quantity'],
                ]);
            }
        });

        it('removes stock below zero when insufficient inventory', function () {
            $products = Product::factory(2)->create();

            // Create initial inventory with limited stock
            InventoryItem::create([
                'product_id' => $products[0]->id,
                'quantity' => 10.0,
            ]);
            InventoryItem::create([
                'product_id' => $products[1]->id,
                'quantity' => 15.0,
            ]);

            $items = [
                ['product_id' => $products[0]->id, 'quantity' => 20.0], // More than available
                ['product_id' => $products[1]->id, 'quantity' => 10.0],  // Within available
            ];

            $result = $this->stockService->removeStock($items, MovementReason::ORDER);

            expect((bool) $result)->toBeTrue();
            $this->assertDatabaseHas('inventory_items', [
                'product_id' => $products[0]->id,
                'quantity' => -10.0,
            ]);
            $this->assertDatabaseHas('inventory_items', [
                'product_id' => $products[1]->id,
                'quantity' => 5.0,
            ]);
        });

        it('fails to remove stock when products dont exist', function () {
            $items = [
                ['product_id' => 99999, 'quantity' => 50.0],
                ['product_id' => 99998, 'quantity' => 75.0],
            ];

            expect(fn () => $this->stockService->removeStock($items, MovementReason::ORDER))
                ->toThrow(StockOperationException::class);
        });
    });

    describe('processMultipleItems', function () {
        it('can process multiple items for adding stock', function () {
            $products = Product::factory(3)->create();
            $items = [
                ['product_id' => $products[0]->id, 'quantity' => 50.0],
                ['product_id' => $products[1]->id, 'quantity' => 75.0],
                ['product_id' => $products[2]->id, 'quantity' => 100.0],
            ];

            $result = $this->stockService->processMultipleItems($items, 'add', MovementReason::PURCHASE);

            expect($result)->toBeTrue();
            foreach ($items as $item) {
                $this->assertDatabaseHas('inventory_items', [
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                ]);
            }
        });

        it('can process multiple items for removing stock', function () {
            $products = Product::factory(3)->create();

            // Create initial inventory
            foreach ($products as $product) {
                InventoryItem::create([
                    'product_id' => $product->id,
                    'quantity' => 100.0,
                ]);
            }

            $items = [
                ['product_id' => $products[0]->id, 'quantity' => 20.0],
                ['product_id' => $products[1]->id, 'quantity' => 30.0],
                ['product_id' => $products[2]->id, 'quantity' => 40.0],
            ];

            $result = $this->stockService->processMultipleItems($items, 'remove', MovementReason::ORDER);

            expect($result)->toBeTrue();
            foreach ($items as $item) {
                $this->assertDatabaseHas('inventory_items', [
                    'product_id' => $item['product_id'],
                    'quantity' => 100.0 - $item['quantity'],
                ]);
            }
        });

        it('fails to process multiple items when products dont exist', function () {
            $items = [
                ['product_id' => 99999, 'quantity' => 50.0],
                ['product_id' => 99998, 'quantity' => 75.0],
            ];

            expect(fn () => $this->stockService->processMultipleItems($items, 'add', MovementReason::PURCHASE))
                ->toThrow(StockOperationException::class);

            $this->assertDatabaseMissing('inventory_items', [
                'product_id' => 99999,
            ]);
            $this->assertDatabaseMissing('inventory_items', [
                'product_id' => 99998,
            ]);
        });

        it('processes multiple items below zero when insufficient stock for removal', function () {
            $products = Product::factory(2)->create();

            // Create initial inventory with limited stock
            InventoryItem::create([
                'product_id' => $products[0]->id,
                'quantity' => 10.0,
            ]);
            InventoryItem::create([
                'product_id' => $products[1]->id,
                'quantity' => 15.0,
            ]);

            $items = [
                ['product_id' => $products[0]->id, 'quantity' => 20.0], // More than available
                ['product_id' => $products[1]->id, 'quantity' => 10.0],  // Within available
            ];

            $result = $this->stockService->processMultipleItems($items, 'remove', MovementReason::ORDER);

            expect((bool) $result)->toBeTrue();
            $this->assertDatabaseHas('inventory_items', [
                'product_id' => $products[0]->id,
                'quantity' => -10.0,
            ]);
            $this->assertDatabaseHas('inventory_items', [
                'product_id' => $products[1]->id,
                'quantity' => 5.0,
            ]);
        });

        it('can handle mixed existing and new inventory items in bulk add', function () {
            $products = Product::factory(3)->create();

            // Create inventory for first product only
            InventoryItem::create([
                'product_id' => $products[0]->id,
                'quantity' => 50.0,
            ]);

            $items = [
                ['product_id' => $products[0]->id, 'quantity' => 30.0], // Update existing
                ['product_id' => $products[1]->id, 'quantity' => 40.0], // Create new
                ['product_id' => $products[2]->id, 'quantity' => 60.0], // Create new
            ];

            $result = $this->stockService->processMultipleItems($items, 'add', MovementReason::PURCHASE);

            expect($result)->toBeTrue();
            $this->assertDatabaseHas('inventory_items', [
                'product_id' => $products[0]->id,
                'quantity' => 80.0, // 50 + 30
            ]);
            $this->assertDatabaseHas('inventory_items', [
                'product_id' => $products[1]->id,
                'quantity' => 40.0,
            ]);
            $this->assertDatabaseHas('inventory_items', [
                'product_id' => $products[2]->id,
                'quantity' => 60.0,
            ]);
        });
    });

    describe('getCurrentStock', function () {
        it('can get current stock for existing product', function () {
            $product = Product::factory()->create();
            $quantity = 75.5;

            InventoryItem::create([
                'product_id' => $product->id,
                'quantity' => $quantity,
            ]);

            $currentStock = $this->stockService->getCurrentStock($product->id);

            expect($currentStock)->toBe($quantity);
        });

        it('returns zero stock for product without inventory', function () {
            $product = Product::factory()->create();

            $currentStock = $this->stockService->getCurrentStock($product->id);

            expect($currentStock)->toBe(0.0);
        });
    });

    describe('hasSufficientStock', function () {
        it('can check if product has sufficient stock', function () {
            $product = Product::factory()->create();
            $availableQuantity = 100.0;

            InventoryItem::create([
                'product_id' => $product->id,
                'quantity' => $availableQuantity,
            ]);

            expect($this->stockService->hasSufficientStock($product->id, 50.0))->toBeTrue()
                ->and($this->stockService->hasSufficientStock($product->id, 100.0))->toBeTrue()
                ->and($this->stockService->hasSufficientStock($product->id, 150.0))->toBeFalse();
        });
    });

    describe('validateStockAvailability', function () {
        it('can validate stock availability for multiple items', function () {
            $products = Product::factory(3)->create();

            // Create inventory with different quantities
            InventoryItem::create(['product_id' => $products[0]->id, 'quantity' => 100.0]);
            InventoryItem::create(['product_id' => $products[1]->id, 'quantity' => 50.0]);
            // No inventory for products[2]

            $items = [
                ['product_id' => $products[0]->id, 'quantity' => 80.0],  // Sufficient
                ['product_id' => $products[1]->id, 'quantity' => 60.0],  // Insufficient
                ['product_id' => $products[2]->id, 'quantity' => 20.0],  // No inventory
            ];

            $insufficientItems = $this->stockService->validateStockAvailability($items);

            expect($insufficientItems)->toHaveCount(2);

            // Check insufficient item details
            expect($insufficientItems[0]['product_id'])->toBe($products[1]->id)
                ->and($insufficientItems[0]['product_name'])->toBe($products[1]->name)
                ->and($insufficientItems[0]['required_quantity'])->toEqual(60.0)
                ->and($insufficientItems[0]['available_quantity'])->toEqual(50.0);

            expect($insufficientItems[1]['product_id'])->toBe($products[2]->id)
                ->and($insufficientItems[1]['product_name'])->toBe($products[2]->name)
                ->and($insufficientItems[1]['required_quantity'])->toEqual(20.0)
                ->and($insufficientItems[1]['available_quantity'])->toEqual(0.0);
        });

        it('returns empty array when all items have sufficient stock', function () {
            $products = Product::factory(2)->create();

            // Create inventory with sufficient quantities
            InventoryItem::create(['product_id' => $products[0]->id, 'quantity' => 100.0]);
            InventoryItem::create(['product_id' => $products[1]->id, 'quantity' => 200.0]);

            $items = [
                ['product_id' => $products[0]->id, 'quantity' => 50.0],
                ['product_id' => $products[1]->id, 'quantity' => 150.0],
            ];

            $insufficientItems = $this->stockService->validateStockAvailability($items);

            expect($insufficientItems)->toBeEmpty();
        });
    });

    describe('movement tracking', function () {
        it('records movements when processing multiple items', function () {
            $products = Product::factory(2)->create();
            $items = [
                ['product_id' => $products[0]->id, 'quantity' => 50.0],
                ['product_id' => $products[1]->id, 'quantity' => 75.0],
            ];

            $result = $this->stockService->processMultipleItems($items, 'add', MovementReason::PURCHASE);

            expect($result)->toBeTrue();

            // Check that movements were recorded
            $movements = InventoryItemMovement::all();
            expect($movements)->toHaveCount(2);

            $movement1 = $movements->where('product_id', $products[0]->id)->first();
            expect($movement1->operation)->toBe(InventoryMovementOperation::IN)
                ->and($movement1->quantity)->toEqual(50.0)
                ->and($movement1->reason)->toBe(MovementReason::PURCHASE);

            $movement2 = $movements->where('product_id', $products[1]->id)->first();
            expect($movement2->operation)->toBe(InventoryMovementOperation::IN)
                ->and($movement2->quantity)->toEqual(75.0)
                ->and($movement2->reason)->toBe(MovementReason::PURCHASE);
        });

        it('records movements for stock removal', function () {
            $product = Product::factory()->create();
            InventoryItem::create(['product_id' => $product->id, 'quantity' => 100.0]);

            $items = [['product_id' => $product->id, 'quantity' => 30.0]];
            $result = $this->stockService->processMultipleItems($items, 'remove', MovementReason::ORDER);

            expect($result)->toBeTrue();

            $movement = InventoryItemMovement::first();
            expect($movement->operation)->toBe(InventoryMovementOperation::OUT)
                ->and($movement->quantity)->toEqual(30.0)
                ->and($movement->reason)->toBe(MovementReason::ORDER);
        });
    });

    describe('getDailyMovementSummary', function () {
        it('can get daily movement summary for a product', function () {
            $product = Product::factory()->create();
            $startDate = \Carbon\Carbon::parse('2024-01-01');
            $endDate = \Carbon\Carbon::parse('2024-01-31');

            $expectedSummary = [
                ['date' => '2024-01-01', 'incoming_quantity' => 100, 'sales_quantity' => 50],
                ['date' => '2024-01-02', 'incoming_quantity' => 0, 'sales_quantity' => 30],
            ];

            $this->dailyAggregationService->shouldReceive('getDailySummary')
                ->with($product->id, $startDate, $endDate)
                ->andReturn($expectedSummary);

            $summary = $this->stockService->getDailyMovementSummary($product->id, $startDate, $endDate);

            expect($summary)->toBe($expectedSummary);
        });
    });

    describe('Phase 1 correctness improvements', function () {
        it('merges duplicate product lines and updates stock correctly', function () {
            $product = Product::factory()->create();
            InventoryItem::create(['product_id' => $product->id, 'quantity' => 10.0]);

            $items = [
                ['product_id' => $product->id, 'quantity' => 2.0],
                ['product_id' => $product->id, 'quantity' => 3.0],
            ];

            $result = $this->stockService->removeStock($items, MovementReason::ORDER);

            expect($result)->toBeInstanceOf(\Illuminate\Support\Collection::class)->not->toBeEmpty();
            expect(InventoryItem::where('product_id', $product->id)->count())->toBe(1);
            expect(InventoryItem::where('product_id', $product->id)->value('quantity'))->toEqual(5.0);

            $movements = InventoryItemMovement::where('product_id', $product->id)->get();
            expect($movements)->toHaveCount(1);
            expect($movements->first()->quantity)->toEqual(5.0);
        });

        it('creates inventory item with negative quantity when outgoing for product without inventory row', function () {
            $product = Product::factory()->create();
            InventoryItem::where('product_id', $product->id)->delete();

            $items = [
                ['product_id' => $product->id, 'quantity' => 4.0],
            ];

            $result = $this->stockService->removeStock($items, MovementReason::ORDER);

            expect($result)->toBeInstanceOf(\Illuminate\Support\Collection::class)->not->toBeEmpty();
            $inventoryItem = InventoryItem::where('product_id', $product->id)->first();
            expect($inventoryItem)->not->toBeNull();
            expect($inventoryItem->quantity)->toEqual(-4.0);
        });

        it('throws StockOperationException and rolls back transaction on failure', function () {
            $product = Product::factory()->create();
            InventoryItem::create(['product_id' => $product->id, 'quantity' => 10.0]);

            $items = [
                ['product_id' => $product->id, 'quantity' => 5.0],
                ['product_id' => 999999, 'quantity' => 5.0],
            ];

            expect(fn () => $this->stockService->removeStock($items, MovementReason::ORDER))
                ->toThrow(StockOperationException::class);

            expect(InventoryItem::where('product_id', $product->id)->value('quantity'))->toEqual(10.0);
            expect(InventoryItemMovement::where('product_id', $product->id)->count())->toBe(0);
        });

        it('throws StockOperationException when no day is open', function () {
            InventoryItemMovementDaily::query()->delete();

            $product = Product::factory()->create();
            InventoryItem::create(['product_id' => $product->id, 'quantity' => 10.0]);

            $items = [
                ['product_id' => $product->id, 'quantity' => 5.0],
            ];

            expect(fn () => $this->stockService->addStock($items, MovementReason::PURCHASE))
                ->toThrow(StockOperationException::class);
        });

        it('creates inventory item when product type changes away from manufactured to consumable', function () {
            $product = Product::factory()->create([
                'type' => \App\Enums\ProductType::Manufactured->value,
            ]);

            expect(InventoryItem::where('product_id', $product->id)->exists())->toBeFalse();

            $product->update([
                'type' => \App\Enums\ProductType::Consumable->value,
            ]);

            expect(InventoryItem::where('product_id', $product->id)->exists())->toBeTrue();
            expect(InventoryItem::where('product_id', $product->id)->value('quantity'))->toEqual(0);
        });

        it('enforces unique product and warehouse on inventory_items table', function () {
            $product = Product::factory()->create();
            InventoryItem::create(['product_id' => $product->id, 'quantity' => 5.0]);

            expect(fn () => InventoryItem::create(['product_id' => $product->id, 'quantity' => 10.0]))
                ->toThrow(\Illuminate\Database\QueryException::class);
        });

        it('stores 3 decimals precision for movements and inventory deduction', function () {
            $product = Product::factory()->create();
            InventoryItem::updateOrCreate(['product_id' => $product->id], ['quantity' => 10.000]);

            $items = [
                ['product_id' => $product->id, 'quantity' => 0.015],
            ];

            $result = $this->stockService->removeStock($items, MovementReason::ORDER);

            expect($result)->toBeInstanceOf(\Illuminate\Support\Collection::class)->not->toBeEmpty();

            $inventoryItem = InventoryItem::where('product_id', $product->id)->first();
            expect((float) $inventoryItem->quantity)->toEqual(9.985);

            $movement = InventoryItemMovement::where('product_id', $product->id)->latest('id')->first();
            expect((float) $movement->quantity)->toEqual(0.015);
        });
    });

});
