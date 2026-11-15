<?php

use App\Orders\TabPermissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function seedRoles(): void
{
    (new RolesAndPermissionsSeeder)->run();
}

function resetPermissionCache(): void
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/** @return array<string, list<string>> */
function snapshotRolePermissions(): array
{
    return Role::with('permissions')->orderBy('name')->get()
        ->mapWithKeys(fn (Role $role) => [$role->name => $role->permissions->pluck('name')->sort()->values()->all()])
        ->all();
}

/** @return list<string> */
function tabPermissionNames(): array
{
    return array_keys(app(TabPermissions::class)->catalog());
}

it('fresh install gives cashier every tab permission and its default permissions', function () {
    Role::query()->delete();
    Permission::query()->delete();
    resetPermissionCache();

    seedRoles();

    $cashier = Role::findByName('cashier', 'web');

    foreach (tabPermissionNames() as $name) {
        expect($cashier->hasPermissionTo($name))->toBeTrue();
    }

    expect($cashier->hasPermissionTo('pos.access'))->toBeTrue()
        ->and($cashier->hasPermissionTo('order.create'))->toBeTrue()
        ->and($cashier->hasPermissionTo('order.cancel'))->toBeFalse()
        ->and(Role::findByName('admin', 'web')->permissions->pluck('name')->all())->toContain('pos.tab.type.dine_in', 'order.cancel');
});

it('second run changes nothing', function () {
    seedRoles();
    $before = snapshotRolePermissions();
    $permissions = Permission::count();

    seedRoles();

    expect(snapshotRolePermissions())->toBe($before)
        ->and(Permission::count())->toBe($permissions);
});

it('does not re-grant a tab permission the owner revoked', function () {
    seedRoles();
    Role::findByName('cashier', 'web')->revokePermissionTo('pos.tab.system.completed');

    seedRoles();

    expect(Role::findByName('cashier', 'web')->hasPermissionTo('pos.tab.system.completed'))->toBeFalse();
});

it('does not remove a permission the owner added to cashier', function () {
    seedRoles();
    Role::findByName('cashier', 'web')->givePermissionTo('order.cancel');

    seedRoles();

    expect(Role::findByName('cashier', 'web')->hasPermissionTo('order.cancel'))->toBeTrue();
});

it('grants a newly introduced permission to its default roles only once', function () {
    seedRoles();
    Permission::where('name', 'invoice.print')->delete();
    resetPermissionCache();

    seedRoles();
    expect(Role::findByName('cashier', 'web')->hasPermissionTo('invoice.print'))->toBeTrue();

    Role::findByName('cashier', 'web')->revokePermissionTo('invoice.print');
    seedRoles();

    expect(Role::findByName('cashier', 'web')->hasPermissionTo('invoice.print'))->toBeFalse();
});

it('gives new tab permissions to every role holding pos.access, including custom roles', function () {
    seedRoles();
    $custom = Role::create(['name' => 'custom_pos', 'guard_name' => 'web']);
    $custom->givePermissionTo('pos.access');
    $noPos = Role::create(['name' => 'no_pos', 'guard_name' => 'web']);

    Permission::where('name', 'like', 'pos.tab.%')->delete();
    resetPermissionCache();

    seedRoles();

    foreach (tabPermissionNames() as $name) {
        expect($custom->fresh()->hasPermissionTo($name))->toBeTrue()
            ->and($noPos->fresh()->hasPermissionTo($name))->toBeFalse();
    }
});

it('creates and grants permissions for order types inserted without model events', function () {
    seedRoles();

    DB::table('order_types')->insert([
        'code' => 'raw_type', 'label' => 'خام', 'color' => 'info', 'icon' => 'heroicon-o-sparkles',
        'is_active' => true, 'is_system' => false, 'behavior' => 'counter',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    seedRoles();

    expect(Role::findByName('cashier', 'web')->hasPermissionTo('pos.tab.type.raw_type'))->toBeTrue();
});
