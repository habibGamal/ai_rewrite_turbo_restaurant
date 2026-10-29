<?php

use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
});

it('can render the index page', function () {
    livewire(ListRoles::class)
        ->assertSuccessful();
});

it('can render the create page', function () {
    livewire(CreateRole::class)
        ->assertSuccessful();
});

it('can render the edit page', function () {
    $record = Role::findByName('cashier', 'web');

    livewire(EditRole::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

it('has column', function (string $column) {
    livewire(ListRoles::class)
        ->assertTableColumnExists($column);
})->with(['name', 'users_count', 'permissions_count', 'created_at']);

it('can render column', function (string $column) {
    livewire(ListRoles::class)
        ->assertCanRenderTableColumn($column);
})->with(['name', 'users_count', 'permissions_count']);

it('can search by role name', function () {
    livewire(ListRoles::class)
        ->searchTable('cashier')
        ->assertCanSeeTableRecords(Role::where('name', 'cashier')->get())
        ->assertCanNotSeeTableRecords(Role::where('name', 'kitchen')->get());
});

it('can create a new role with specific permissions', function () {
    livewire(CreateRole::class)
        ->fillForm([
            'name' => 'inventory_auditor',
            'permissions_purchasing' => ['view_inventory_items', 'view_stocktakings'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Role::class, [
        'name' => 'inventory_auditor',
        'guard_name' => 'web',
    ]);

    $role = Role::findByName('inventory_auditor', 'web');
    expect($role->hasPermissionTo('view_inventory_items'))->toBeTrue()
        ->and($role->hasPermissionTo('view_stocktakings'))->toBeTrue()
        ->and($role->hasPermissionTo('order.cancel'))->toBeFalse();
});

it('can update role and sync permissions', function () {
    $role = Role::create(['name' => 'lead_cashier', 'guard_name' => 'web']);
    $role->givePermissionTo('pos.access');

    livewire(EditRole::class, ['record' => $role->getRouteKey()])
        ->fillForm([
            'name' => 'senior_cashier',
            'permissions_pos' => ['pos.access', 'order.apply_discount'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $role->refresh();
    expect($role->name)->toBe('senior_cashier')
        ->and($role->hasPermissionTo('order.apply_discount'))->toBeTrue();
});

it('prevents deleting the admin role', function () {
    $adminRole = Role::findByName('admin', 'web');

    livewire(EditRole::class, ['record' => $adminRole->getRouteKey()])
        ->assertActionHidden(DeleteAction::class);
});

it('can delete a custom role', function () {
    $customRole = Role::create(['name' => 'temporary_staff', 'guard_name' => 'web']);

    livewire(EditRole::class, ['record' => $customRole->getRouteKey()])
        ->callAction(DeleteAction::class);

    $this->assertModelMissing($customRole);
});

it('validates required name', function () {
    livewire(CreateRole::class)
        ->fillForm(['name' => null])
        ->call('create')
        ->assertHasFormErrors(['name' => ['required']]);
});

it('validates unique name', function () {
    livewire(CreateRole::class)
        ->fillForm(['name' => 'admin'])
        ->call('create')
        ->assertHasFormErrors(['name' => ['unique']]);
});

it('validates max length of name', function () {
    livewire(CreateRole::class)
        ->fillForm(['name' => Str::random(256)])
        ->call('create')
        ->assertHasFormErrors(['name' => ['max:255']]);
});
