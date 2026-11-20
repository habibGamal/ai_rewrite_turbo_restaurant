<?php

use App\Models\Setting;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;

function legacyToggle(string $key, string $value): void
{
    Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

dataset('legacy toggles', [
    'discounts' => ['allow_cashier_discounts', 'order.apply_discount'],
    'cancel' => ['allow_cashier_cancel_orders', 'order.cancel'],
    'item changes' => ['allow_cashier_item_changes', 'order.change_items'],
]);

it('grants the mapped permission to cashier when the legacy toggle is on and deletes the setting', function (string $key, string $permission) {
    expect(Role::findByName('cashier', 'web')->hasPermissionTo($permission))->toBeFalse();

    legacyToggle($key, 'true');
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::findByName('cashier', 'web')->hasPermissionTo($permission))->toBeTrue()
        ->and(Setting::where('key', $key)->exists())->toBeFalse();
})->with('legacy toggles');

it('accepts every truthy stored value', function (string $stored) {
    legacyToggle('allow_cashier_cancel_orders', $stored);
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::findByName('cashier', 'web')->hasPermissionTo('order.cancel'))->toBeTrue();
})->with(['1', 'true']);

it('never revokes a permission granted on the roles screen when the legacy toggle is off', function (string $key, string $permission) {
    Role::findByName('cashier', 'web')->givePermissionTo($permission);

    legacyToggle($key, 'false');
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::findByName('cashier', 'web')->hasPermissionTo($permission))->toBeTrue()
        ->and(Setting::where('key', $key)->exists())->toBeFalse();
})->with('legacy toggles');

it('does not grant anything when the legacy toggle is off', function (string $key, string $permission) {
    legacyToggle($key, 'false');
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::findByName('cashier', 'web')->hasPermissionTo($permission))->toBeFalse();
})->with('legacy toggles');

it('is a no-op on the second run', function () {
    legacyToggle('allow_cashier_discounts', 'true');
    $this->seed(RolesAndPermissionsSeeder::class);

    $snapshot = fn () => Role::with('permissions')->orderBy('name')->get()
        ->mapWithKeys(fn (Role $role) => [$role->name => $role->permissions->pluck('name')->sort()->values()->all()])
        ->all();

    $before = $snapshot();
    $this->seed(RolesAndPermissionsSeeder::class);

    expect($snapshot())->toBe($before);
});
