<?php

use App\Enums\UserRole;
use App\Filament\Resources\Drivers\Pages\CreateDriver;
use App\Filament\Resources\Drivers\Pages\EditDriver;
use App\Filament\Resources\Drivers\Pages\ListDrivers;
use App\Filament\Resources\Drivers\Pages\ViewDriver;
use App\Filament\Resources\Drivers\RelationManagers\OrdersRelationManager;
use App\Models\Driver;
use App\Models\Order;
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
    livewire(ListDrivers::class)
        ->assertSuccessful();
});

it('can render the create page', function () {
    livewire(CreateDriver::class)
        ->assertSuccessful();
});

it('can render the edit page', function () {
    $record = Driver::factory()->create();

    livewire(EditDriver::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

it('can render the view page', function () {
    $record = Driver::factory()->create();

    livewire(ViewDriver::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

// Table Column Tests
it('has column', function (string $column) {
    livewire(ListDrivers::class)
        ->assertTableColumnExists($column);
})->with(['name', 'phone', 'orders_count', 'created_at', 'updated_at']);

it('can render column', function (string $column) {
    livewire(ListDrivers::class)
        ->assertCanRenderTableColumn($column);
})->with(['name', 'phone', 'orders_count', 'created_at', 'updated_at']);

// Table Sorting Tests
it('can sort column', function (string $column) {
    $records = Driver::factory(5)->create();

    livewire(ListDrivers::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($column))
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($column));
})->with(['name', 'phone', 'created_at', 'updated_at']);

// Table Search Tests
it('can search by name', function () {
    $records = Driver::factory(5)->create();

    $value = $records->first()->name;

    livewire(ListDrivers::class)
        ->searchTable($value)
        ->assertCanSeeTableRecords($records->where('name', $value))
        ->assertCanNotSeeTableRecords($records->where('name', '!=', $value));
});

it('can search by phone', function () {
    $records = Driver::factory(5)->create();

    $value = $records->first()->phone;

    livewire(ListDrivers::class)
        ->searchTable($value)
        ->assertCanSeeTableRecords($records->where('phone', $value))
        ->assertCanNotSeeTableRecords($records->where('phone', '!=', $value));
});

// CRUD Operations
it('can create a driver', function () {
    $record = Driver::factory()->make();

    livewire(CreateDriver::class)
        ->fillForm([
            'name' => $record->name,
            'phone' => $record->phone,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Driver::class, [
        'name' => $record->name,
        'phone' => $record->phone,
    ]);
});

it('can update a driver', function () {
    $record = Driver::factory()->create();
    $newRecord = Driver::factory()->make();

    livewire(EditDriver::class, ['record' => $record->getRouteKey()])
        ->fillForm([
            'name' => $newRecord->name,
            'phone' => $newRecord->phone,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Driver::class, [
        'id' => $record->id,
        'name' => $newRecord->name,
        'phone' => $newRecord->phone,
    ]);
});

it('can view a driver', function () {
    $record = Driver::factory()->create();

    livewire(ViewDriver::class, ['record' => $record->getRouteKey()])
        ->assertSchemaStateSet([
            'name' => $record->name,
            'phone' => $record->phone,
        ]);
});

it('can delete a driver', function () {
    $record = Driver::factory()->create();

    livewire(EditDriver::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete')
        ->callAction(DeleteAction::class);

    $this->assertModelMissing($record);
});

it('can bulk delete drivers', function () {
    $records = Driver::factory(5)->create();

    livewire(ListDrivers::class)
        ->callTableBulkAction('delete', $records);

    foreach ($records as $record) {
        $this->assertModelMissing($record);
    }
});

// Form Validation Tests
it('can validate required name', function () {
    livewire(CreateDriver::class)
        ->fillForm(['name' => null])
        ->call('create')
        ->assertHasFormErrors(['name' => ['required']]);
});

it('can validate max length on name', function () {
    livewire(CreateDriver::class)
        ->fillForm(['name' => Str::random(256)])
        ->call('create')
        ->assertHasFormErrors(['name' => ['max:255']]);
});

it('can validate max length on phone', function () {
    livewire(CreateDriver::class)
        ->fillForm([
            'name' => 'Test Driver',
            'phone' => Str::random(256),
        ])
        ->call('create')
        ->assertHasFormErrors(['phone' => ['max:255']]);
});

// Table Actions Tests
it('has view action on list page', function () {
    $record = Driver::factory()->create();

    livewire(ListDrivers::class)
        ->assertTableActionExists('view');
});

it('has edit action on list page', function () {
    $record = Driver::factory()->create();

    livewire(ListDrivers::class)
        ->assertTableActionExists('edit');
});

it('has delete action on list page', function () {
    $record = Driver::factory()->create();

    livewire(ListDrivers::class)
        ->assertTableActionExists('delete');
});

// Page Actions Tests
it('has view action on edit page header', function () {
    $record = Driver::factory()->create();

    livewire(EditDriver::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('view');
});

it('has delete action on edit page header', function () {
    $record = Driver::factory()->create();

    livewire(EditDriver::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete');
});

it('has edit action on view page header', function () {
    $record = Driver::factory()->create();

    livewire(ViewDriver::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('edit');
});

// Record Visibility Tests
it('can see table records', function () {
    $records = Driver::factory(5)->create();

    livewire(ListDrivers::class)
        ->assertCanSeeTableRecords($records);
});

it('can count table records', function () {
    Driver::factory(3)->create();

    livewire(ListDrivers::class)
        ->assertCountTableRecords(3);
});

// Relationship Tests
it('shows correct orders count', function () {
    $driver = Driver::factory()->create();
    Order::factory()->count(3)->create(['driver_id' => $driver->id]);

    $driver->refresh();

    livewire(ListDrivers::class)
        ->assertSee($driver->name)
        ->assertSee('3'); // Should see the orders count displayed
});

// Column Toggleability Tests
it('created_at and updated_at are toggleable', function () {
    livewire(ListDrivers::class)
        ->assertTableColumnExists('created_at')
        ->assertTableColumnExists('updated_at');
});
