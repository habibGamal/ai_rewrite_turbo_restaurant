<?php

use App\Enums\OrderBehavior;
use App\Models\OrderTypeRow;
use App\Orders\TabPermissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function newOrderType(string $code, string $label = 'نوع جديد'): OrderTypeRow
{
    return OrderTypeRow::query()->create([
        'code' => $code,
        'label' => $label,
        'color' => 'info',
        'icon' => 'heroicon-o-sparkles',
        'is_active' => true,
        'is_system' => false,
        'behavior' => OrderBehavior::COUNTER,
    ]);
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('maps tab keys to permission names', function () {
    expect(TabPermissions::forKey('type:dine_in'))->toBe('pos.tab.type.dine_in')
        ->and(TabPermissions::forKey('system:completed'))->toBe('pos.tab.system.completed')
        ->and(TabPermissions::forTypeCode('talabat'))->toBe('pos.tab.type.talabat')
        ->and(fn () => TabPermissions::forKey('bogus:x'))->toThrow(InvalidArgumentException::class);
});

it('catalogs every order type and system tab', function () {
    $catalog = app(TabPermissions::class)->catalog();

    foreach (OrderTypeRow::all() as $row) {
        expect($catalog)->toHaveKey('pos.tab.type.'.$row->code);
    }

    expect($catalog)->toHaveKeys([
        'pos.tab.system.receivables',
        'pos.tab.system.expenses',
        'pos.tab.system.cancelled',
        'pos.tab.system.completed',
    ]);
});

it('creating an order type creates its permission for admin and every pos.access role', function () {
    $custom = Role::create(['name' => 'custom_pos', 'guard_name' => 'web']);
    $custom->givePermissionTo('pos.access');
    $kitchen = Role::findByName('kitchen', 'web');

    newOrderType('tab_test');

    expect(Permission::where('name', 'pos.tab.type.tab_test')->exists())->toBeTrue()
        ->and(Role::findByName('admin', 'web')->hasPermissionTo('pos.tab.type.tab_test'))->toBeTrue()
        ->and(Role::findByName('cashier', 'web')->hasPermissionTo('pos.tab.type.tab_test'))->toBeTrue()
        ->and($custom->fresh()->hasPermissionTo('pos.tab.type.tab_test'))->toBeTrue()
        ->and($kitchen->hasPermissionTo('pos.tab.type.tab_test'))->toBeFalse();
});

it('deleting a custom order type removes its permission', function () {
    $row = newOrderType('tab_gone');
    expect(Permission::where('name', 'pos.tab.type.tab_gone')->exists())->toBeTrue();

    $row->delete();

    expect(Permission::where('name', 'pos.tab.type.tab_gone')->exists())->toBeFalse();
});

it('renames the permission and keeps role grants when the code changes', function () {
    $row = newOrderType('tab_old');
    $row->update(['code' => 'tab_new']);

    expect(Permission::where('name', 'pos.tab.type.tab_old')->exists())->toBeFalse()
        ->and(Role::findByName('cashier', 'web')->hasPermissionTo('pos.tab.type.tab_new'))->toBeTrue();
});

it('sync deletes orphaned type permissions and keeps system ones', function () {
    Permission::create(['name' => 'pos.tab.type.ghost', 'guard_name' => 'web']);

    app(TabPermissions::class)->sync();

    expect(Permission::where('name', 'pos.tab.type.ghost')->exists())->toBeFalse()
        ->and(Permission::where('name', 'pos.tab.system.completed')->exists())->toBeTrue()
        ->and(Permission::where('name', 'pos.tab.type.dine_in')->exists())->toBeTrue();
});

it('sync returns only the permissions it created', function () {
    expect(app(TabPermissions::class)->sync())->toBe([]);

    Permission::where('name', 'pos.tab.system.expenses')->delete();

    expect(app(TabPermissions::class)->sync())->toBe(['pos.tab.system.expenses']);
});
