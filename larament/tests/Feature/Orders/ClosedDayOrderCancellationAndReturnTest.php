<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ProductType;
use App\Models\Category;
use App\Models\DineTable;
use App\Models\InventoryItem;
use App\Models\InventoryItemMovementDaily;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Shift;
use App\Models\User;
use App\Services\InventoryDailyAggregationService;
use App\Services\Orders\OrderReturnService;
use App\Services\Orders\OrderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->shift = Shift::factory()->create([
        'user_id' => $this->user->id,
        'closed' => false,
    ]);

    $this->category = Category::firstOrCreate(
        ['name' => 'Phase2 Test Category'],
        ['name' => 'Phase2 Test Category', 'description' => 'Test']
    );

    // Construct minimal records directly to avoid pre-existing products.printer_id factory issue
    $this->product = Product::create([
        'name' => 'Burger Phase2',
        'product_ref' => 'REF-P2-01',
        'category_id' => $this->category->id,
        'price' => 25.00,
        'cost' => 15.00,
        'min_stock' => 5,
        'type' => ProductType::Consumable,
        'unit' => 'piece',
        'legacy' => false,
    ]);

    // Set initial inventory
    InventoryItem::updateOrCreate(
        ['product_id' => $this->product->id, 'warehouse_id' => \App\Models\Warehouse::default()->id],
        ['quantity' => 100.0]
    );

    $this->completedOrder = Order::create([
        'user_id' => $this->user->id,
        'shift_id' => $this->shift->id,
        'status' => OrderStatus::COMPLETED,
        'type' => 'dine_in',
        'dine_table_number' => 'T10',
        'sub_total' => 50.00,
        'tax' => 0,
        'service' => 0,
        'discount' => 0,
        'temp_discount_percent' => 0,
        'total' => 50.00,
        'profit' => 20.00,
        'payment_status' => PaymentStatus::FULL_PAID,
        'order_number' => 9901,
    ]);

    $this->orderItem = OrderItem::create([
        'order_id' => $this->completedOrder->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
        'price' => 25.00,
        'cost' => 15.00,
        'total' => 50.00,
    ]);

    $this->payment = Payment::create([
        'order_id' => $this->completedOrder->id,
        'amount' => 50.00,
        'method' => PaymentMethod::CASH,
        'shift_id' => $this->shift->id,
    ]);

    $this->table = DineTable::create([
        'table_number' => 'T10',
        'order_id' => $this->completedOrder->id,
    ]);
});

describe('Phase 2: Closed Day Order Cancellation and Return', function () {
    it('(a) cancelling a COMPLETED order with no open day throws and leaves order status, payments and table untouched', function () {
        // Ensure day is closed
        expect(app(InventoryDailyAggregationService::class)->dayStatus())->toBeNull();

        $orderService = app(OrderService::class);

        expect(fn () => $orderService->cancelOrder($this->completedOrder->id))
            ->toThrow(Exception::class, 'يجب فتح اليوم قبل إجراء أي عمليات على المخزون');

        $this->completedOrder->refresh();
        expect($this->completedOrder->status)->toBe(OrderStatus::COMPLETED);
        expect($this->completedOrder->payments()->count())->toBe(1);
        expect(DineTable::where('table_number', 'T10')->value('order_id'))->toBe($this->completedOrder->id);
    });

    it('(b) cancelling a non-completed order with no open day still works', function () {
        // Ensure day is closed
        expect(app(InventoryDailyAggregationService::class)->dayStatus())->toBeNull();

        $processingOrder = Order::create([
            'user_id' => $this->user->id,
            'shift_id' => $this->shift->id,
            'status' => OrderStatus::PROCESSING,
            'type' => 'dine_in',
            'dine_table_number' => 'T20',
            'sub_total' => 25.00,
            'tax' => 0,
            'service' => 0,
            'discount' => 0,
            'temp_discount_percent' => 0,
            'total' => 25.00,
            'profit' => 10.00,
            'payment_status' => PaymentStatus::PENDING,
            'order_number' => 9902,
        ]);

        DineTable::create([
            'table_number' => 'T20',
            'order_id' => $processingOrder->id,
        ]);

        $orderService = app(OrderService::class);
        $cancelledOrder = $orderService->cancelOrder($processingOrder->id);

        expect($cancelledOrder->status)->toBe(OrderStatus::CANCELLED);
        expect($processingOrder->fresh()->status)->toBe(OrderStatus::CANCELLED);
        expect(DineTable::where('table_number', 'T20')->value('order_id'))->toBeNull();
    });

    it('(c) processReturn with no open day throws and creates no return', function () {
        // Ensure day is closed
        expect(app(InventoryDailyAggregationService::class)->dayStatus())->toBeNull();

        $returnService = app(OrderReturnService::class);

        $returnItems = [
            [
                'order_item_id' => $this->orderItem->id,
                'quantity' => 1,
                'refund_amount' => 25.00,
            ],
        ];

        $refundDistribution = [
            [
                'method' => 'cash',
                'amount' => 25.00,
            ],
        ];

        expect(fn () => $returnService->processReturn(
            $this->completedOrder,
            $returnItems,
            'Customer return reason',
            $refundDistribution,
            $this->shift->id,
            true
        ))->toThrow(Exception::class, 'يجب فتح اليوم قبل إجراء أي عمليات على المخزون');

        expect(OrderReturn::count())->toBe(0);
        expect(OrderReturnItem::count())->toBe(0);
        expect(Refund::count())->toBe(0);
    });

    it('cancels completed order and restores stock when day is open', function () {
        openInventoryDay($this->product);

        $initialStock = (float) InventoryItem::where('product_id', $this->product->id)->value('quantity');

        $orderService = app(OrderService::class);
        $cancelledOrder = $orderService->cancelOrder($this->completedOrder->id);

        expect($cancelledOrder->status)->toBe(OrderStatus::CANCELLED);
        expect($this->completedOrder->fresh()->status)->toBe(OrderStatus::CANCELLED);
        expect($this->completedOrder->payments()->count())->toBe(0);
        expect(DineTable::where('table_number', 'T10')->value('order_id'))->toBeNull();

        $newStock = (float) InventoryItem::where('product_id', $this->product->id)->value('quantity');
        expect($newStock)->toBe($initialStock + 2.0);
    });

    it('processes return successfully when day is open', function () {
        openInventoryDay($this->product);

        $initialStock = (float) InventoryItem::where('product_id', $this->product->id)->value('quantity');

        $returnService = app(OrderReturnService::class);

        $returnItems = [
            [
                'order_item_id' => $this->orderItem->id,
                'quantity' => 1,
                'refund_amount' => 25.00,
            ],
        ];

        $refundDistribution = [
            [
                'method' => 'cash',
                'amount' => 25.00,
            ],
        ];

        $return = $returnService->processReturn(
            $this->completedOrder,
            $returnItems,
            'Customer return reason',
            $refundDistribution,
            $this->shift->id,
            true
        );

        expect($return)->toBeInstanceOf(OrderReturn::class);
        expect(OrderReturn::count())->toBe(1);
        expect(OrderReturnItem::count())->toBe(1);
        expect(Refund::count())->toBe(1);

        $newStock = (float) InventoryItem::where('product_id', $this->product->id)->value('quantity');
        expect($newStock)->toBe($initialStock + 1.0);
    });

    it('logs warning during openDay when live inventory quantity differs from last end_quantity', function () {
        $product = Product::create([
            'name' => 'Discrepancy Test Product',
            'product_ref' => 'REF-P2-02',
            'category_id' => $this->category->id,
            'price' => 10.00,
            'cost' => 5.00,
            'min_stock' => 2,
            'type' => ProductType::Consumable,
            'unit' => 'piece',
            'legacy' => false,
        ]);

        InventoryItem::updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => \App\Models\Warehouse::default()->id],
            ['quantity' => 80.0]
        );

        $yesterday = Carbon::yesterday();
        InventoryItemMovementDaily::create([
            'product_id' => $product->id,
            'warehouse_id' => \App\Models\Warehouse::default()->id,
            'date' => $yesterday->toDateString(),
            'start_quantity' => 100.0,
            'incoming_quantity' => 0.0,
            'return_sales_quantity' => 0.0,
            'sales_quantity' => 0.0,
            'return_waste_quantity' => 0.0,
            'end_quantity' => 100.0,
            'closed_at' => now(),
        ]);

        Log::spy();

        $aggregationService = app(InventoryDailyAggregationService::class);
        $aggregationService->openDay();

        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context) use ($product) {
            return isset($context['product_id'], $context['last_end_quantity'], $context['live_quantity'])
                && $context['product_id'] === $product->id
                && (float) $context['last_end_quantity'] == 100.0
                && (float) $context['live_quantity'] == 80.0;
        });

        $todayRecord = InventoryItemMovementDaily::where('product_id', $product->id)
            ->whereDate('date', Carbon::today())
            ->first();

        expect($todayRecord)->not()->toBeNull();
        expect((float) $todayRecord->start_quantity)->toBe(100.0);
    });
});
