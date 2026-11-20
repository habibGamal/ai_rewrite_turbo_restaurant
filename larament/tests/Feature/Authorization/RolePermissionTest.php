<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

it('grants admin role full access to all gates and abilities', function () {
    $admin = User::factory()->admin()->create();
    actingAs($admin);

    expect($admin->isAdmin())->toBeTrue()
        ->and($admin->can('access_admin_panel'))->toBeTrue()
        ->and($admin->can('manage_settings'))->toBeTrue()
        ->and($admin->can('view_categories'))->toBeTrue()
        ->and($admin->can('create_categories'))->toBeTrue()
        ->and($admin->can('pos.access'))->toBeTrue()
        ->and($admin->can('kitchen.access'))->toBeTrue()
        ->and($admin->can('order.cancel'))->toBeTrue()
        ->and($admin->can('order.apply_discount'))->toBeTrue();
});

it('restricts cashier from accessing administrative pages and kitchen', function () {
    $cashier = User::factory()->cashier()->create();
    actingAs($cashier);

    expect($cashier->can('pos.access'))->toBeTrue()
        ->and($cashier->can('access_admin_panel'))->toBeFalse()
        ->and($cashier->can('manage_settings'))->toBeFalse()
        ->and($cashier->can('kitchen.access'))->toBeFalse()
        ->and($cashier->can('create_categories'))->toBeFalse();
});

it('enforces order cancellation permission', function () {
    $cashierWithoutCancel = User::factory()->cashier()->create();
    expect($cashierWithoutCancel->canCancelOrders())->toBeFalse();

    $cashierWithCancel = User::factory()->cashier()->create();
    $cashierWithCancel->givePermissionTo('order.cancel');
    expect($cashierWithCancel->canCancelOrders())->toBeTrue();
});

it('enforces order discount permission', function () {
    $cashierWithoutDiscount = User::factory()->cashier()->create();
    expect($cashierWithoutDiscount->canApplyDiscounts())->toBeFalse();

    $cashierWithDiscount = User::factory()->cashier()->create();
    $cashierWithDiscount->givePermissionTo('order.apply_discount');
    expect($cashierWithDiscount->canApplyDiscounts())->toBeTrue();
});

it('restricts kitchen user to kitchen screen only', function () {
    $kitchen = User::factory()->kitchen()->create();
    actingAs($kitchen);

    expect($kitchen->can('kitchen.access'))->toBeTrue()
        ->and($kitchen->can('pos.access'))->toBeFalse()
        ->and($kitchen->can('access_admin_panel'))->toBeFalse();
});

it('restricts viewer user to reports and denies product editing or POS', function () {
    $viewer = User::factory()->viewer()->create();
    actingAs($viewer);

    expect($viewer->isViewer())->toBeTrue()
        ->and($viewer->can('access_admin_panel'))->toBeTrue()
        ->and($viewer->can('view_reports'))->toBeTrue()
        ->and($viewer->can('create_products'))->toBeFalse()
        ->and($viewer->can('pos.access'))->toBeFalse();
});

it('allows creating custom roles with granular permissions', function () {
    $supervisorRole = Role::create(['name' => 'supervisor', 'guard_name' => 'web']);
    $supervisorRole->syncPermissions([
        'access_admin_panel',
        'view_categories',
        'view_products',
        'view_orders',
        'order.cancel',
    ]);

    $user = User::factory()->create();
    $user->syncRoles(['supervisor']);

    expect($user->can('access_admin_panel'))->toBeTrue()
        ->and($user->can('view_categories'))->toBeTrue()
        ->and($user->can('view_products'))->toBeTrue()
        ->and($user->can('order.cancel'))->toBeTrue()
        ->and($user->can('create_categories'))->toBeFalse()
        ->and($user->can('kitchen.access'))->toBeFalse();
});
