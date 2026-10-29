<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\SettingKey;
use App\Models\Category;
use App\Models\ExpenceType;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\PurchaseInvoice;
use App\Models\ReturnPurchaseInvoice;
use App\Models\Shift;
use App\Models\Stocktaking;
use App\Models\Supplier;
use App\Models\SyncOutbox;
use App\Models\User;
use App\Models\Waste;
use App\Services\Orders\OrderService;
use App\Services\SettingsService;
use App\Services\ShiftService;
use App\Services\WebApiService;
use App\Sync\DocumentType;
use Illuminate\Support\Facades\Http;

it('records nothing for any model when not paired', function () {
    $order = Order::factory()->create(['status' => OrderStatus::COMPLETED]);
    $order->update(['total' => 999]);
    $order->delete();

    $shift = Shift::factory()->create();
    $shift->delete();

    $user = User::factory()->create();
    $expenseType = ExpenceType::factory()->create();
    $expense = Expense::factory()->create(['expence_type_id' => $expenseType->id]);
    $expense->delete();

    $supplier = Supplier::factory()->create();
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id]);

    $waste = Waste::factory()->create(['closed_at' => now(), 'user_id' => $user->id]);
    $waste->delete();

    expect(SyncOutbox::count())->toBe(0);
});

it('records the order when it is completed, not while it is processing', function () {
    pairPlatform();

    $order = Order::factory()->create(['status' => OrderStatus::PROCESSING]);
    expect(SyncOutbox::where('doc_type', DocumentType::Order->value)->count())->toBe(0);

    $order->update(['status' => OrderStatus::COMPLETED]);

    expect(SyncOutbox::where([
        'doc_type' => DocumentType::Order->value,
        'doc_uuid' => $order->uuid,
        'op' => 'upsert',
    ])->count())->toBe(1);
});

it('records the order when it is cancelled, not while it is pending', function () {
    pairPlatform();

    $order = Order::factory()->create(['status' => OrderStatus::PENDING]);
    expect(SyncOutbox::where('doc_type', DocumentType::Order->value)->count())->toBe(0);

    $order->update(['status' => OrderStatus::CANCELLED]);

    expect(SyncOutbox::where([
        'doc_type' => DocumentType::Order->value,
        'doc_uuid' => $order->uuid,
        'op' => 'upsert',
    ])->count())->toBe(1);
});

it('records a delete tombstone when a completed or cancelled order is deleted', function () {
    pairPlatform();

    $order = Order::factory()->create(['status' => OrderStatus::COMPLETED]);
    SyncOutbox::truncate();

    $order->delete();

    expect(SyncOutbox::where([
        'doc_type' => DocumentType::Order->value,
        'doc_uuid' => $order->uuid,
        'op' => 'delete',
    ])->count())->toBe(1);
});

it('does not record when a non-final order is deleted', function () {
    pairPlatform();

    $order = Order::factory()->create(['status' => OrderStatus::PROCESSING]);
    SyncOutbox::truncate();

    $order->delete();

    expect(SyncOutbox::where('doc_type', DocumentType::Order->value)->count())->toBe(0);
});

it('records the parent order when an item of a completed order changes or is deleted', function () {
    pairPlatform();

    $order = Order::factory()->create(['status' => OrderStatus::COMPLETED]);
    $product = Product::factory()->create();
    SyncOutbox::truncate();

    $item = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 2,
    ]);

    expect(SyncOutbox::where([
        'doc_type' => DocumentType::Order->value,
        'doc_uuid' => $order->uuid,
        'op' => 'upsert',
    ])->count())->toBe(1);

    SyncOutbox::truncate();

    $item->update(['quantity' => 3]);

    expect(SyncOutbox::where([
        'doc_type' => DocumentType::Order->value,
        'doc_uuid' => $order->uuid,
        'op' => 'upsert',
    ])->count())->toBe(1);

    SyncOutbox::truncate();

    $item->delete();

    // Deleting an item should record parent order upsert (to re-snapshot the order without that item)
    expect(SyncOutbox::where([
        'doc_type' => DocumentType::Order->value,
        'doc_uuid' => $order->uuid,
        'op' => 'upsert',
    ])->count())->toBe(1);
});

it('does not record parent order when an item of a processing order changes or is deleted', function () {
    pairPlatform();

    $order = Order::factory()->create(['status' => OrderStatus::PROCESSING]);
    $product = Product::factory()->create();
    SyncOutbox::truncate();

    $item = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
    ]);
    $item->update(['quantity' => 5]);
    $item->delete();

    expect(SyncOutbox::where('doc_type', DocumentType::Order->value)->count())->toBe(0);
});

it('records the parent order when a payment of a completed order is saved or deleted', function () {
    pairPlatform();

    $order = Order::factory()->create(['status' => OrderStatus::COMPLETED]);
    $shift = Shift::factory()->create();
    SyncOutbox::truncate();

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'shift_id' => $shift->id,
        'amount' => 50,
    ]);

    expect(SyncOutbox::where([
        'doc_type' => DocumentType::Order->value,
        'doc_uuid' => $order->uuid,
        'op' => 'upsert',
    ])->count())->toBe(1);

    SyncOutbox::truncate();

    $payment->delete();

    // Deleting a payment should record parent order upsert (to re-snapshot the order)
    expect(SyncOutbox::where([
        'doc_type' => DocumentType::Order->value,
        'doc_uuid' => $order->uuid,
        'op' => 'upsert',
    ])->count())->toBe(1);
});

it('does not record parent order when a payment of a processing order is saved or deleted', function () {
    pairPlatform();

    $order = Order::factory()->create(['status' => OrderStatus::PROCESSING]);
    $shift = Shift::factory()->create();
    SyncOutbox::truncate();

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'shift_id' => $shift->id,
    ]);
    $payment->delete();

    expect(SyncOutbox::where('doc_type', DocumentType::Order->value)->count())->toBe(0);
});

it('records order_return and parent order when an order return is created', function () {
    pairPlatform();

    $order = Order::factory()->create(['status' => OrderStatus::COMPLETED]);
    $user = User::factory()->create();
    $shift = Shift::factory()->create();
    SyncOutbox::truncate();

    $return = OrderReturn::create([
        'order_id' => $order->id,
        'user_id' => $user->id,
        'shift_id' => $shift->id,
        'total_refund' => 50,
        'reason' => 'Damaged item',
    ]);

    expect(SyncOutbox::where([
        'doc_type' => DocumentType::OrderReturn->value,
        'doc_uuid' => $return->uuid,
        'op' => 'upsert',
    ])->count())->toBe(1)
        ->and(SyncOutbox::where([
            'doc_type' => DocumentType::Order->value,
            'doc_uuid' => $order->uuid,
            'op' => 'upsert',
        ])->count())->toBe(1);
});

it('records shift when saved', function () {
    pairPlatform();

    $shift = Shift::factory()->create();

    expect(SyncOutbox::where([
        'doc_type' => DocumentType::Shift->value,
        'doc_uuid' => $shift->uuid,
        'op' => 'upsert',
    ])->count())->toBe(1);

    SyncOutbox::truncate();

    $shift->update(['closed' => true, 'end_at' => now()]);

    expect(SyncOutbox::where([
        'doc_type' => DocumentType::Shift->value,
        'doc_uuid' => $shift->uuid,
        'op' => 'upsert',
    ])->count())->toBe(1);
});

it('records expense when saved and delete tombstone when deleted', function () {
    pairPlatform();

    $type = ExpenceType::factory()->create();
    SyncOutbox::truncate();

    $expense = Expense::factory()->create(['expence_type_id' => $type->id]);

    expect(SyncOutbox::where([
        'doc_type' => DocumentType::Expense->value,
        'doc_uuid' => $expense->uuid,
        'op' => 'upsert',
    ])->count())->toBe(1);

    SyncOutbox::truncate();

    $expense->delete();

    expect(SyncOutbox::where([
        'doc_type' => DocumentType::Expense->value,
        'doc_uuid' => $expense->uuid,
        'op' => 'delete',
    ])->count())->toBe(1);
});

it('records closed documents only when closed_at is not null and delete tombstone on delete', function (string $modelClass, DocumentType $expectedType) {
    pairPlatform();

    $user = User::factory()->create();
    $supplier = Supplier::factory()->create();
    SyncOutbox::truncate();

    if ($modelClass === ReturnPurchaseInvoice::class) {
        $doc = ReturnPurchaseInvoice::factory()->create([
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
            'closed_at' => null,
        ]);
    } elseif ($modelClass === PurchaseInvoice::class) {
        $doc = PurchaseInvoice::factory()->create([
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
            'closed_at' => null,
        ]);
    } elseif ($modelClass === Waste::class) {
        $doc = Waste::factory()->create([
            'user_id' => $user->id,
            'closed_at' => null,
        ]);
    } else {
        $doc = Stocktaking::factory()->create([
            'user_id' => $user->id,
            'closed_at' => null,
        ]);
    }

    // Unclosed -> not recorded
    expect(SyncOutbox::where('doc_type', $expectedType->value)->count())->toBe(0);

    // Closing -> recorded
    $doc->update(['closed_at' => now()]);

    expect(SyncOutbox::where([
        'doc_type' => $expectedType->value,
        'doc_uuid' => $doc->uuid,
        'op' => 'upsert',
    ])->count())->toBe(1);

    SyncOutbox::truncate();

    // Deleting a closed doc -> op delete
    $doc->delete();

    expect(SyncOutbox::where([
        'doc_type' => $expectedType->value,
        'doc_uuid' => $doc->uuid,
        'op' => 'delete',
    ])->count())->toBe(1);
})->with([
    [PurchaseInvoice::class, DocumentType::PurchaseInvoice],
    [ReturnPurchaseInvoice::class, DocumentType::PurchaseReturn],
    [Waste::class, DocumentType::Waste],
    [Stocktaking::class, DocumentType::Stocktaking],
]);

it('does not record when unclosed documents are deleted', function () {
    pairPlatform();

    $user = User::factory()->create();
    SyncOutbox::truncate();

    $waste = Waste::factory()->create(['user_id' => $user->id, 'closed_at' => null]);
    $waste->delete();

    expect(SyncOutbox::where('doc_type', DocumentType::Waste->value)->count())->toBe(0);
});

it('records catalog models (product, category, supplier, expense_type, staff) when saved or deleted', function (string $modelClass, DocumentType $expectedType) {
    pairPlatform();

    SyncOutbox::truncate();

    if ($modelClass === Product::class) {
        $category = Category::factory()->create();
        SyncOutbox::truncate();
        $record = Product::factory()->create(['category_id' => $category->id]);
    } else {
        $record = $modelClass::factory()->create();
    }

    expect(SyncOutbox::where([
        'doc_type' => $expectedType->value,
        'doc_uuid' => $record->uuid,
        'op' => 'upsert',
    ])->count())->toBe(1);

    SyncOutbox::truncate();

    $record->delete();

    expect(SyncOutbox::where([
        'doc_type' => $expectedType->value,
        'doc_uuid' => $record->uuid,
        'op' => 'delete',
    ])->count())->toBe(1);
})->with([
    [Product::class, DocumentType::Product],
    [Category::class, DocumentType::Category],
    [Supplier::class, DocumentType::Supplier],
    [ExpenceType::class, DocumentType::ExpenseType],
    [User::class, DocumentType::Staff],
]);

it('records the parent product recipe when a component is saved or deleted', function () {
    pairPlatform();

    $category = Category::factory()->create();
    $parent = Product::factory()->create(['category_id' => $category->id]);
    $component = Product::factory()->create(['category_id' => $category->id]);
    SyncOutbox::truncate();

    $productComponent = ProductComponent::create([
        'product_id' => $parent->id,
        'component_id' => $component->id,
        'quantity' => 2.5,
    ]);

    expect(SyncOutbox::where([
        'doc_type' => DocumentType::Recipe->value,
        'doc_uuid' => $parent->uuid,
        'op' => 'upsert',
    ])->count())->toBe(1);

    SyncOutbox::truncate();

    $productComponent->update(['quantity' => 3.0]);

    expect(SyncOutbox::where([
        'doc_type' => DocumentType::Recipe->value,
        'doc_uuid' => $parent->uuid,
        'op' => 'upsert',
    ])->count())->toBe(1);

    SyncOutbox::truncate();

    $productComponent->delete();

    // Deleting a component should re-trigger parent recipe upsert
    expect(SyncOutbox::where([
        'doc_type' => DocumentType::Recipe->value,
        'doc_uuid' => $parent->uuid,
        'op' => 'upsert',
    ])->count())->toBe(1);
});

describe('Bulk write handling', function () {
    it('records the order when cancelOrder deletes payments via query builder on a completed order', function () {
        pairPlatform();

        openInventoryDay();

        $user = User::factory()->create();
        $shift = Shift::factory()->create(['closed' => false]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'shift_id' => $shift->id,
            'status' => OrderStatus::COMPLETED,
        ]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'shift_id' => $shift->id,
            'amount' => 100,
        ]);

        SyncOutbox::truncate();

        $orderService = app(OrderService::class);
        $orderService->cancelOrder($order->id);

        expect(SyncOutbox::where([
            'doc_type' => DocumentType::Order->value,
            'doc_uuid' => $order->uuid,
            'op' => 'upsert',
        ])->count())->toBeGreaterThanOrEqual(1);
    });

    it('records the order when rejectOrder cancels and deletes payments via query builder', function () {
        Http::fake();
        pairPlatform();

        $user = User::factory()->create();
        $shift = Shift::factory()->create(['closed' => false]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'shift_id' => $shift->id,
            'status' => OrderStatus::PROCESSING,
        ]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'shift_id' => $shift->id,
            'amount' => 50,
        ]);

        SyncOutbox::truncate();

        $webApiService = app(WebApiService::class);
        $webApiService->rejectOrder($order->id);

        expect(SyncOutbox::where([
            'doc_type' => DocumentType::Order->value,
            'doc_uuid' => $order->uuid,
            'op' => 'upsert',
        ])->count())->toBeGreaterThanOrEqual(1);
    });

    it('confirms ShiftService::transferOrdersFromLastShift only moves open web orders and does not record to outbox', function () {
        pairPlatform();
        openInventoryDay();

        $settingsService = app(SettingsService::class);
        $settingsService->set(SettingKey::ALLOW_WEB_ORDERS_SHIFT_TRANSFER->value, 'true');

        $user = User::factory()->create();
        $this->actingAs($user);

        $shift1 = Shift::factory()->create([
            'closed' => true,
            'end_at' => now()->subHour(),
            'user_id' => $user->id,
        ]);

        $openWebOrder = Order::factory()->create([
            'shift_id' => $shift1->id,
            'type' => OrderType::WEB_DELIVERY,
            'status' => OrderStatus::PROCESSING,
        ]);

        SyncOutbox::truncate();

        $shiftService = app(ShiftService::class);
        $shift2 = $shiftService->startShift(100);

        expect($openWebOrder->fresh()->shift_id)->toBe($shift2->id)
            ->and(SyncOutbox::where('doc_type', DocumentType::Order->value)->count())->toBe(0);
    });
});
