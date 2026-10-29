<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;

it('applies permission middleware to grouped routes', function (string $name, string $permission) {
    expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('permission:'.$permission);
})->with([
    ['orders.store', 'pos.access'],
    ['orders.complete', 'pos.access'],
    ['web-orders.accept', 'web_order.manage'],
    ['web-orders.reject', 'web_order.manage'],
    ['admin.printers.scan', 'view_printers'],
    ['expenses.store', 'pos.access'],
]);
it('denies printer scanning to users without permission', function () {
    Permission::findOrCreate('view_printers', 'web');
    $user = User::factory()->create();
    $user->syncRoles([]);
    $this->actingAs($user)->post(route('admin.printers.scan'))->assertForbidden();
});
