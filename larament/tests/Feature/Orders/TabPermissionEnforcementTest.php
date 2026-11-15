<?php

use App\Enums\OrderStatus;
use App\Models\ExpenceType;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Orders\TabPermissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

/**
 * A POS user whose role holds pos.access, the desk action permissions and exactly the
 * listed tab keys (e.g. 'type:dine_in', 'system:expenses').
 *
 * @param  list<string>  $tabKeys
 */
function deskUserWith(array $tabKeys): User
{
    static $counter = 0;
    $counter++;

    $role = Role::create(['name' => 'desk_role_'.$counter, 'guard_name' => 'web']);
    $role->givePermissionTo([
        'pos.access', 'order.create', 'order.complete', 'order.cancel', 'expense.create',
        ...array_map(TabPermissions::forKey(...), $tabKeys),
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

/** @return list<string> every tab key except the given ones */
function allTabKeysExcept(array $except): array
{
    $keys = array_map(
        fn (string $permission): string => str_starts_with($permission, TabPermissions::TYPE_PREFIX)
            ? 'type:'.substr($permission, strlen(TabPermissions::TYPE_PREFIX))
            : 'system:'.substr($permission, strlen(TabPermissions::SYSTEM_PREFIX)),
        array_keys(app(TabPermissions::class)->catalog()),
    );

    return array_values(array_diff($keys, $except));
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->shiftOwner = User::factory()->admin()->create();
    $this->shift = Shift::factory()->create([
        'user_id' => $this->shiftOwner->id,
        'end_at' => null,
        'closed' => false,
    ]);
});

function deskOrder(string $type, OrderStatus $status = OrderStatus::PROCESSING): Order
{
    return Order::factory()->create([
        'shift_id' => test()->shift->id,
        'user_id' => test()->shiftOwner->id,
        'type' => $type,
        'status' => $status,
    ]);
}

it('omits tabs the user lacks, including a forced-visible hidden tab', function () {
    app(\App\Services\SettingsService::class)->set(\App\Enums\SettingKey::CASHIER_TABS->value, [
        ['key' => 'type:dine_in', 'visible' => true],
        ['key' => 'type:delivery', 'visible' => false],
        ['key' => 'type:takeaway', 'visible' => true],
    ]);
    deskOrder('delivery');

    $withoutDelivery = deskUserWith(allTabKeysExcept(['type:delivery', 'type:takeaway']));

    actingAs($withoutDelivery)->get(route('orders.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tabs', function ($tabs) {
                $keys = collect($tabs)->pluck('key');

                return ! $keys->contains('type:delivery')
                    && ! $keys->contains('type:takeaway')
                    && $keys->contains('type:dine_in');
            }));

    // An admin still gets the forced-visible hidden tab.
    actingAs($this->shiftOwner)->get(route('orders.index'))
        ->assertInertia(fn ($page) => $page->where('tabs', fn ($tabs) => collect($tabs)->contains('key', 'type:delivery')));
});

it('shows the empty tab set to a user without any tab permission', function () {
    actingAs(deskUserWith([]))->get(route('orders.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('tabs', 0)->has('orders', 0));
});

it('does not send orders of types the user cannot see', function () {
    deskOrder('dine_in');
    deskOrder('delivery');

    actingAs(deskUserWith(['type:dine_in']))->get(route('orders.index'))
        ->assertInertia(fn ($page) => $page
            ->has('orders', 1)
            ->where('orders.0.type', 'dine_in'));
});

it('does not send completed or cancelled orders without those tab permissions', function () {
    deskOrder('dine_in');
    deskOrder('dine_in', OrderStatus::COMPLETED);
    deskOrder('dine_in', OrderStatus::CANCELLED);

    actingAs(deskUserWith(['type:dine_in']))->get(route('orders.index'))
        ->assertInertia(fn ($page) => $page->has('orders', 1)->where('orders.0.status', 'processing'));

    actingAs(deskUserWith(['type:dine_in', 'system:completed']))->get(route('orders.index'))
        ->assertInertia(fn ($page) => $page->has('orders', 2));

    actingAs(deskUserWith(['system:cancelled']))->get(route('orders.index'))
        ->assertInertia(fn ($page) => $page->has('orders', 1)->where('orders.0.status', 'cancelled'));
});

it('sends completed and cancelled orders of a type the user cannot see only through the system tabs', function () {
    deskOrder('delivery', OrderStatus::COMPLETED);
    deskOrder('delivery', OrderStatus::CANCELLED);
    deskOrder('delivery');

    actingAs(deskUserWith(['system:completed']))->get(route('orders.index'))
        ->assertInertia(fn ($page) => $page->has('orders', 1)->where('orders.0.status', 'completed'));
});

it('sends orders in a status the type tab itself presents', function () {
    // web_delivery presents pending / processing / out_for_delivery
    deskOrder('web_delivery', OrderStatus::OUT_FOR_DELIVERY);
    deskOrder('web_delivery', OrderStatus::PENDING);
    deskOrder('web_delivery', OrderStatus::COMPLETED);

    actingAs(deskUserWith(['type:web_delivery']))->get(route('orders.index'))
        ->assertInertia(fn ($page) => $page->has('orders', 2));
});

it('does not send expenses or expense types without pos.tab.system.expenses', function () {
    ExpenceType::factory()->create();

    actingAs(deskUserWith(['type:dine_in']))->get(route('orders.index'))
        ->assertInertia(fn ($page) => $page->has('expenses', 0)->has('expenseTypes', 0));

    actingAs(deskUserWith(['system:expenses']))->get(route('orders.index'))
        ->assertInertia(fn ($page) => $page->where('expenseTypes', fn ($types) => count($types) >= 1));
});

it('does not send receivables without pos.tab.system.receivables', function () {
    $user = deskUserWith(['type:companies']);

    actingAs($user)->get(route('orders.index'))
        ->assertInertia(fn ($page) => $page->has('receivables', 0));
});

it('forbids expense routes without pos.tab.system.expenses', function () {
    $type = ExpenceType::factory()->create();
    $payload = ['amount' => 10, 'expenseTypeId' => $type->id, 'description' => 'test'];

    actingAs(deskUserWith(['type:dine_in']))
        ->post(route('expenses.store'), $payload)
        ->assertForbidden();

    actingAs(deskUserWith(['type:dine_in']))
        ->get(route('expenses.index'))
        ->assertForbidden();

    actingAs(deskUserWith(['system:expenses']))
        ->post(route('expenses.store'), $payload)
        ->assertSessionDoesntHaveErrors()
        ->assertStatus(302);
});

it('refuses to open an order of a type the user cannot see', function () {
    actingAs(deskUserWith(['type:dine_in']))
        ->post(route('orders.store'), ['type' => 'takeaway'])
        ->assertForbidden();

    expect(Order::where('type', 'takeaway')->count())->toBe(0);

    actingAs(deskUserWith(['type:takeaway']))
        ->post(route('orders.store'), ['type' => 'takeaway'])
        ->assertRedirect();

    expect(Order::where('type', 'takeaway')->count())->toBe(1);
});

it('refuses to change an order to a type the user cannot see', function () {
    $order = deskOrder('takeaway');

    actingAs(deskUserWith(['type:takeaway']))
        ->post(route('orders.updateType', $order), ['type' => 'delivery'])
        ->assertSessionHasErrors(['error' => 'غير مسموح لك بهذا النوع من الطلبات']);

    expect($order->fresh()->type)->toBe('takeaway');
});

it('limits the change-type options on the manage page to types the user can see', function () {
    $order = deskOrder('takeaway');

    actingAs(deskUserWith(['type:takeaway', 'type:delivery']))
        ->get(route('orders.manage', $order))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('changeTypeOptions', function ($options) {
            $codes = collect($options)->pluck('code');

            return $codes->contains('delivery') && ! $codes->contains('dine_in');
        }));
});

it('forbids managing an order whose tab the user cannot see', function () {
    $deliveryOrder = deskOrder('delivery');
    $user = deskUserWith(allTabKeysExcept(['type:delivery']));

    actingAs($user)->get(route('orders.manage', $deliveryOrder))->assertForbidden();
    actingAs($user)->post(route('orders.save', $deliveryOrder), ['items' => []])->assertForbidden();
    actingAs($user)->post(route('orders.cancel', $deliveryOrder))->assertForbidden();
    actingAs($user)->post(route('orders.complete', $deliveryOrder))->assertForbidden();
    actingAs($user)->post(route('orders.updateType', $deliveryOrder), ['type' => 'takeaway'])->assertForbidden();
    actingAs($user)->post(route('orders.updateNotes', $deliveryOrder), ['order_notes' => 'x'])->assertForbidden();
    actingAs($user)->post(route('print', $deliveryOrder))->assertForbidden();
});

it('lets a user with the type tab manage an order of that type', function () {
    $order = deskOrder('delivery');

    actingAs(deskUserWith(['type:delivery']))->get(route('orders.manage', $order))->assertOk();
});

it('lets a user with only the completed tab open a completed order', function () {
    $order = deskOrder('delivery', OrderStatus::COMPLETED);

    actingAs(deskUserWith(['system:completed']))->get(route('orders.manage', $order))->assertOk();
    actingAs(deskUserWith(['system:cancelled']))->get(route('orders.manage', $order))->assertForbidden();
});

it('lets a user with only the cancelled tab open a cancelled order', function () {
    $order = deskOrder('delivery', OrderStatus::CANCELLED);

    actingAs(deskUserWith(['system:cancelled']))->get(route('orders.manage', $order))->assertOk();
});

it('does not restrict admins', function () {
    $order = deskOrder('delivery');

    actingAs($this->shiftOwner)->get(route('orders.manage', $order))->assertOk();
    actingAs($this->shiftOwner)->post(route('orders.store'), ['type' => 'takeaway'])->assertRedirect();
});

it('forbids web-order actions on an order whose type tab the user cannot see', function () {
    $webOrder = deskOrder('web_delivery', OrderStatus::PENDING);
    $user = deskUserWith(allTabKeysExcept(['type:web_delivery']));
    $user->givePermissionTo('web_order.manage');

    actingAs($user)->get(route('web-orders.manage', $webOrder))->assertForbidden();
    actingAs($user)->post(route('web-orders.accept', $webOrder))->assertForbidden();
    actingAs($user)->post(route('web-orders.reject', $webOrder))->assertForbidden();
    actingAs($user)->post(route('web-orders.complete', $webOrder))->assertForbidden();
    actingAs($user)->post(route('web-orders.outForDelivery', $webOrder))->assertForbidden();
    actingAs($user)->post(route('web-orders.applyDiscount', $webOrder))->assertForbidden();
    actingAs($user)->post(route('web-orders.saveOrder', $webOrder))->assertForbidden();
});

it('lets a user with the web type tab open that web order', function () {
    $webOrder = deskOrder('web_delivery', OrderStatus::PENDING);
    $user = deskUserWith(['type:web_delivery']);
    $user->givePermissionTo('web_order.manage');

    actingAs($user)->get(route('web-orders.manage', $webOrder))->assertOk();
});
