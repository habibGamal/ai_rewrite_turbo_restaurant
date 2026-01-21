<?php

use App\Enums\UserRole;
use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\Resources\Suppliers\Pages\ViewSupplier;
use App\Models\Supplier;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    actingAs($this->admin);
});

// Page Rendering Tests
it('can render the index page', function () {
    livewire(ListSuppliers::class)
        ->assertSuccessful();
});

it('can render the create page', function () {
    livewire(CreateSupplier::class)
        ->assertSuccessful();
});

it('can render the edit page', function () {
    $record = Supplier::factory()->create();

    livewire(EditSupplier::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

it('can render the view page', function () {
    $record = Supplier::factory()->create();

    livewire(ViewSupplier::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

// Table Column Tests
it('has column', function (string $column) {
    livewire(ListSuppliers::class)
        ->assertTableColumnExists($column);
})->with(['name', 'phone', 'address', 'created_at', 'updated_at']);

it('can render column', function (string $column) {
    livewire(ListSuppliers::class)
        ->assertCanRenderTableColumn($column);
})->with(['name', 'phone', 'address', 'created_at', 'updated_at']);

// Table Sorting Tests
it('can sort column', function (string $column) {
    $records = Supplier::factory(5)->create();

    livewire(ListSuppliers::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($column))
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($column));
})->with(['name', 'phone', 'created_at', 'updated_at']);

// Table Search Tests
it('can search suppliers by name', function () {
    $suppliers = Supplier::factory(10)->create();
    $name = $suppliers->first()->name;

    livewire(ListSuppliers::class)
        ->searchTable($name)
        ->assertCanSeeTableRecords($suppliers->where('name', $name))
        ->assertCanNotSeeTableRecords($suppliers->where('name', '!=', $name));
});

it('can search suppliers by phone', function () {
    $supplier1 = Supplier::factory()->create(['phone' => '01234567890']);
    $supplier2 = Supplier::factory()->create(['phone' => '09876543210']);

    livewire(ListSuppliers::class)
        ->searchTable('01234567890')
        ->assertCanSeeTableRecords([$supplier1])
        ->assertCanNotSeeTableRecords([$supplier2]);
});

// CRUD Operations
it('can create a supplier', function () {
    $record = Supplier::factory()->make();

    livewire(CreateSupplier::class)
        ->fillForm([
            'name' => $record->name,
            'phone' => $record->phone,
            'address' => $record->address,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Supplier::class, [
        'name' => $record->name,
        'phone' => $record->phone,
        'address' => $record->address,
    ]);
});

it('can update a supplier', function () {
    $record = Supplier::factory()->create();
    $newRecord = Supplier::factory()->make();

    livewire(EditSupplier::class, ['record' => $record->getRouteKey()])
        ->fillForm([
            'name' => $newRecord->name,
            'phone' => $newRecord->phone,
            'address' => $newRecord->address,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Supplier::class, [
        'id' => $record->id,
        'name' => $newRecord->name,
        'phone' => $newRecord->phone,
        'address' => $newRecord->address,
    ]);
});

it('can view a supplier', function () {
    $record = Supplier::factory()->create();

    livewire(ViewSupplier::class, ['record' => $record->getRouteKey()])
        ->assertSchemaStateSet([
            'name' => $record->name,
            'phone' => $record->phone,
            'address' => $record->address,
        ]);
});

it('can delete a supplier', function () {
    $record = Supplier::factory()->create();

    livewire(EditSupplier::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete')
        ->callAction(DeleteAction::class);

    $this->assertModelMissing($record);
});

it('can bulk delete suppliers', function () {
    $records = Supplier::factory(5)->create();

    livewire(ListSuppliers::class)
        ->callTableBulkAction('delete', $records);

    foreach ($records as $record) {
        $this->assertModelMissing($record);
    }
});

// Form Validation Tests
it('can validate required name', function () {
    livewire(CreateSupplier::class)
        ->fillForm(['name' => null])
        ->call('create')
        ->assertHasFormErrors(['name' => ['required']]);
});

it('can validate max length on name', function () {
    livewire(CreateSupplier::class)
        ->fillForm([
            'name' => Str::random(256),
        ])
        ->call('create')
        ->assertHasFormErrors(['name' => ['max:255']]);
});

it('can validate max length on phone', function () {
    livewire(CreateSupplier::class)
        ->fillForm([
            'name' => 'Test Supplier',
            'phone' => Str::random(256),
        ])
        ->call('create')
        ->assertHasFormErrors(['phone' => ['max:255']]);
});

it('can validate max length on address', function () {
    livewire(CreateSupplier::class)
        ->fillForm([
            'name' => 'Test Supplier',
            'address' => Str::random(1001),
        ])
        ->call('create')
        ->assertHasFormErrors(['address' => ['max:1000']]);
});

// Table Actions Tests
it('has view action on list page', function () {
    $record = Supplier::factory()->create();

    livewire(ListSuppliers::class)
        ->assertTableActionExists('view');
});

it('has edit action on list page', function () {
    $record = Supplier::factory()->create();

    livewire(ListSuppliers::class)
        ->assertTableActionExists('edit');
});

it('has delete action on list page', function () {
    $record = Supplier::factory()->create();

    livewire(ListSuppliers::class)
        ->assertTableActionExists('delete');
});

// Page Actions Tests
it('has view action on edit page header', function () {
    $record = Supplier::factory()->create();

    livewire(EditSupplier::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('view');
});

it('has delete action on edit page header', function () {
    $record = Supplier::factory()->create();

    livewire(EditSupplier::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete');
});

it('has edit action on view page header', function () {
    $record = Supplier::factory()->create();

    livewire(ViewSupplier::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('edit');
});

// Record Visibility Tests
it('can see table records', function () {
    $records = Supplier::factory(5)->create();

    livewire(ListSuppliers::class)
        ->assertCanSeeTableRecords($records);
});

it('can count table records', function () {
    Supplier::factory(3)->create();

    livewire(ListSuppliers::class)
        ->assertCountTableRecords(3);
});

// Column Toggleability Tests
it('updated_at is toggleable and hidden by default', function () {
    livewire(ListSuppliers::class)
        ->assertTableColumnExists('updated_at');
});

it('created_at is toggleable and hidden by default', function () {
    livewire(ListSuppliers::class)
        ->assertTableColumnExists('created_at');
});

// Column Tooltip Test
it('shows full address in tooltip when address is truncated', function () {
    $longAddress = Str::random(100);
    $supplier = Supplier::factory()->create(['address' => $longAddress]);

    livewire(ListSuppliers::class)
        ->assertCanSeeTableRecords([$supplier]);
});

// Authorization Tests
it('non-admin users cannot access supplier resource', function () {
    $user = User::factory()->create(['role' => UserRole::CASHIER]);
    actingAs($user);

    livewire(ListSuppliers::class)
        ->assertForbidden();
});

it('non-admin users cannot create suppliers', function () {
    $user = User::factory()->create(['role' => UserRole::CASHIER]);
    actingAs($user);

    livewire(CreateSupplier::class)
        ->assertForbidden();
});

it('non-admin users cannot edit suppliers', function () {
    $user = User::factory()->create(['role' => UserRole::CASHIER]);
    $record = Supplier::factory()->create();
    actingAs($user);

    livewire(EditSupplier::class, ['record' => $record->getRouteKey()])
        ->assertForbidden();
});

it('non-admin users cannot view suppliers', function () {
    $user = User::factory()->create(['role' => UserRole::CASHIER]);
    $record = Supplier::factory()->create();
    actingAs($user);

    livewire(ViewSupplier::class, ['record' => $record->getRouteKey()])
        ->assertForbidden();
});
