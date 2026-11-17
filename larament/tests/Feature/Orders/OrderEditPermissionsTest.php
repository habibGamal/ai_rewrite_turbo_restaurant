<?php

use App\Models\OrderTypeRow;
use App\Models\User;
use App\Orders\TabPermissions;
use App\Services\Orders\OrderService;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\Support\SafetyNet;

beforeEach(function () {
    ['user' => $this->admin, 'shift' => $this->shift] = SafetyNet::till();
    OrderTypeRow::query()->where('code', 'instant_sale')->update(['is_active' => true]);
    $this->burger = SafetyNet::product(price: 100);
    $this->fries = SafetyNet::product(price: 30);

    $this->cashier = User::factory()->cashier()->create();
    $this->cashier->revokePermissionTo(['order.change_items', 'order.apply_discount']);
    foreach (['takeaway', 'instant_sale'] as $code) {
        $this->cashier->givePermissionTo(Permission::findOrCreate(TabPermissions::forTypeCode($code), TabPermissions::GUARD));
    }
    expect($this->cashier->can('order.change_items'))->toBeFalse()
        ->and($this->cashier->can('order.apply_discount'))->toBeFalse();
});

it('never lets a fixed order discount go past the items subtotal', function () {
    $order = SafetyNet::open($this->shift, $this->admin);
    SafetyNet::save($order, [[$this->fries, 1]]);

    $order = app(OrderService::class)->applyDiscount($order->id, 80, 'value');

    expect((float) $order->discount)->toBe(30.0)
        ->and((float) $order->total)->toBeGreaterThanOrEqual(0.0);
});

it('stops a cashier without order.change_items from lowering a saved line', function () {
    $order = SafetyNet::open($this->shift, $this->admin);
    SafetyNet::save($order, [[$this->burger, 2]]);

    $this->actingAs($this->cashier)
        ->post(route('orders.save', $order), ['items' => [['product_id' => $this->burger->id, 'quantity' => 1, 'price' => 100]]])
        ->assertForbidden()->assertSee('بتقليل أو حذف');

    $this->actingAs($this->cashier)
        ->post(route('orders.save', $order), ['items' => [['product_id' => $this->fries->id, 'quantity' => 1, 'price' => 30]]])
        ->assertForbidden();

    // Adding more is fine.
    $this->actingAs($this->cashier)
        ->post(route('orders.save', $order), ['items' => [
            ['product_id' => $this->burger->id, 'quantity' => 3, 'price' => 100],
        ]])->assertSessionHasNoErrors()->assertRedirect();

    expect((float) $order->fresh()->items()->sum('quantity'))->toBe(3.0);
});

it('stops a cashier without order.apply_discount from adding a line discount', function () {
    $order = SafetyNet::open($this->shift, $this->admin);

    $this->actingAs($this->cashier)
        ->post(route('orders.save', $order), ['items' => [
            ['product_id' => $this->burger->id, 'quantity' => 1, 'price' => 100, 'item_discount' => 50, 'item_discount_type' => 'value'],
        ]])->assertForbidden()->assertSee('بتطبيق خصم');

    $this->actingAs($this->cashier)
        ->postJson(route('pos.instant-sales.store'), [
            'client_uuid' => (string) Str::uuid(),
            'type' => 'instant_sale',
            'items' => [['product_id' => $this->burger->id, 'quantity' => 1, 'item_discount' => 50, 'item_discount_type' => 'value']],
            'tenders' => ['cash' => 50],
        ])->assertForbidden()->assertJsonPath('message', 'غير مسموح لك بتطبيق الخصم');
});
