<?php

use App\Models\Category;
use App\Models\ExpenceType;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\ReturnPurchaseInvoice;
use App\Models\Shift;
use App\Models\Stocktaking;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Waste;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('assigns a uuid on create for every synced model', function (string $model) {
    if ($model === OrderReturn::class) {
        $order = Order::factory()->create();
        $user = User::factory()->create();
        $shift = Shift::factory()->create();
        $record = OrderReturn::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'shift_id' => $shift->id,
            'total_refund' => 50,
            'reason' => 'test return',
        ]);
    } elseif ($model === OrderItem::class) {
        $order = Order::factory()->create();
        $product = Product::factory()->create();
        $record = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
        ]);
    } elseif ($model === Expense::class) {
        $type = ExpenceType::factory()->create();
        $record = Expense::factory()->create([
            'expence_type_id' => $type->id,
        ]);
    } elseif ($model === ReturnPurchaseInvoice::class) {
        $user = User::factory()->create();
        $supplier = Supplier::factory()->create();
        $record = ReturnPurchaseInvoice::factory()->create([
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
        ]);
    } else {
        $record = $model::factory()->create();
    }

    expect($record->uuid)->toBeString()
        ->and(Str::isUuid($record->uuid))->toBeTrue();
})->with([
    Order::class,
    OrderItem::class,
    OrderReturn::class,
    Shift::class,
    Expense::class,
    ExpenceType::class,
    PurchaseInvoice::class,
    ReturnPurchaseInvoice::class,
    Waste::class,
    Stocktaking::class,
    Product::class,
    Category::class,
    Supplier::class,
    User::class,
]);

it('keeps integer primary keys and route binding by id', function () {
    $order = Order::factory()->create();

    expect($order->getKeyType())->toBe('int')
        ->and($order->getIncrementing())->toBeTrue()
        ->and($order->getRouteKeyName())->toBe('id');
});

it('backfills uuids for rows created before the migration', function () {
    DB::table('categories')->insert([
        'name' => 'old category',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('categories')->update(['uuid' => null]);

    /** @var object $migration */
    $migration = include database_path('migrations/2026_11_01_000001_add_uuid_to_synced_tables.php');
    $migration->backfill();

    expect(DB::table('categories')->whereNull('uuid')->count())->toBe(0);
});
