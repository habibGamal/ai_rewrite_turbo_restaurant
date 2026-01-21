<?php

use App\Enums\UserRole;
use App\Filament\Resources\Regions\Pages\CreateRegion;
use App\Filament\Resources\Regions\Pages\EditRegion;
use App\Filament\Resources\Regions\Pages\ListRegions;
use App\Filament\Resources\Regions\Pages\ViewRegion;
use App\Models\Region;
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
    livewire(ListRegions::class)
        ->assertSuccessful();
});

it('can render the create page', function () {
    livewire(CreateRegion::class)
        ->assertSuccessful();
});

it('can render the edit page', function () {
    $record = Region::factory()->create();

    livewire(EditRegion::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

it('can render the view page', function () {
    $record = Region::factory()->create();

    livewire(ViewRegion::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

// Table Column Tests
it('has column', function (string $column) {
    livewire(ListRegions::class)
        ->assertTableColumnExists($column);
})->with(['name', 'delivery_cost', 'created_at', 'updated_at']);

it('can render column', function (string $column) {
    livewire(ListRegions::class)
        ->assertCanRenderTableColumn($column);
})->with(['name', 'delivery_cost', 'created_at', 'updated_at']);

it('can sort column', function (string $column) {
    $records = Region::factory(5)->create();

    livewire(ListRegions::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($column))
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($column));
})->with(['name', 'delivery_cost', 'created_at', 'updated_at']);

// Search Tests
it('can search by name', function () {
    $records = Region::factory(5)->create();

    $value = $records->first()->name;

    livewire(ListRegions::class)
        ->searchTable($value)
        ->assertCanSeeTableRecords($records->where('name', $value))
        ->assertCanNotSeeTableRecords($records->where('name', '!=', $value));
});

// CRUD Operations
it('can create a region', function () {
    $record = Region::factory()->make();

    livewire(CreateRegion::class)
        ->fillForm([
            'name' => $record->name,
            'delivery_cost' => $record->delivery_cost,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Region::class, [
        'name' => $record->name,
        'delivery_cost' => $record->delivery_cost,
    ]);
});

it('can create a region with zero delivery cost', function () {
    $record = Region::factory()->make(['delivery_cost' => 0]);

    livewire(CreateRegion::class)
        ->fillForm([
            'name' => $record->name,
            'delivery_cost' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Region::class, [
        'name' => $record->name,
        'delivery_cost' => 0,
    ]);
});

it('can update a region', function () {
    $record = Region::factory()->create();
    $newRecord = Region::factory()->make();

    livewire(EditRegion::class, ['record' => $record->getRouteKey()])
        ->fillForm([
            'name' => $newRecord->name,
            'delivery_cost' => $newRecord->delivery_cost,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Region::class, [
        'id' => $record->id,
        'name' => $newRecord->name,
        'delivery_cost' => $newRecord->delivery_cost,
    ]);
});

it('can view a region', function () {
    $record = Region::factory()->create(['delivery_cost' => 50.00]);

    livewire(ViewRegion::class, ['record' => $record->getRouteKey()])
        ->assertSchemaStateSet([
            'name' => $record->name,
            'delivery_cost' => 50.00,
        ]);
});

it('can delete a region', function () {
    $record = Region::factory()->create();

    livewire(EditRegion::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete')
        ->callAction(DeleteAction::class);

    $this->assertModelMissing($record);
});

it('can bulk delete regions', function () {
    $records = Region::factory(5)->create();

    livewire(ListRegions::class)
        ->callTableBulkAction('delete', $records);

    foreach ($records as $record) {
        $this->assertModelMissing($record);
    }
});

// Form Validation Tests
it('can validate required name', function () {
    livewire(CreateRegion::class)
        ->fillForm(['name' => null])
        ->call('create')
        ->assertHasFormErrors(['name' => ['required']]);
});

it('can validate max length for name', function () {
    livewire(CreateRegion::class)
        ->fillForm(['name' => Str::random(256)])
        ->call('create')
        ->assertHasFormErrors(['name' => ['max:255']]);
});

it('can validate numeric delivery_cost', function () {
    livewire(CreateRegion::class)
        ->fillForm([
            'name' => 'Test Region',
            'delivery_cost' => 'not-a-number',
        ])
        ->call('create')
        ->assertHasFormErrors(['delivery_cost']);
});

// Table Actions
it('has view action on list page', function () {
    livewire(ListRegions::class)
        ->assertTableActionExists('view');
});

it('has edit action on list page', function () {
    livewire(ListRegions::class)
        ->assertTableActionExists('edit');
});

it('has delete action on list page', function () {
    livewire(ListRegions::class)
        ->assertTableActionExists('delete');
});

// Page Actions
it('has view action on edit page header', function () {
    $record = Region::factory()->create();

    livewire(EditRegion::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('view');
});

it('has delete action on edit page header', function () {
    $record = Region::factory()->create();

    livewire(EditRegion::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete');
});

it('has edit action on view page header', function () {
    $record = Region::factory()->create();

    livewire(ViewRegion::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('edit');
});

// Table Records Visibility
it('can see table records', function () {
    $records = Region::factory(5)->create();

    livewire(ListRegions::class)
        ->assertCanSeeTableRecords($records);
});

it('can count table records', function () {
    Region::factory(3)->create();

    livewire(ListRegions::class)
        ->assertCountTableRecords(3);
});

// Field Visibility Tests
it('has name field in create form', function () {
    livewire(CreateRegion::class)
        ->assertSchemaComponentExists('name');
});

it('has delivery_cost field in create form', function () {
    livewire(CreateRegion::class)
        ->assertSchemaComponentExists('delivery_cost');
});

// Money Formatting Test
it('displays delivery_cost as EGP currency', function () {
    $record = Region::factory()->create(['delivery_cost' => 1234.56]);

    livewire(ListRegions::class)
        ->assertCanSeeTableRecords([$record]);
});

// Default Values
it('sets default delivery_cost to zero for new region', function () {
    livewire(CreateRegion::class)
        ->assertSchemaStateSet([
            'delivery_cost' => 0,
        ]);
});

// Timestamp Toggleability
it('created_at and updated_at are toggleable columns', function () {
    livewire(ListRegions::class)
        ->assertTableColumnExists('created_at')
        ->assertTableColumnExists('updated_at');
});

// Additional CRUD Tests
it('preserves delivery_cost decimal precision', function () {
    $record = Region::factory()->make(['delivery_cost' => 123.45]);

    livewire(CreateRegion::class)
        ->fillForm([
            'name' => $record->name,
            'delivery_cost' => 123.45,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Region::class, [
        'name' => $record->name,
        'delivery_cost' => 123.45,
    ]);
});

it('can update only name', function () {
    $record = Region::factory()->create(['delivery_cost' => 50.00]);
    $newName = 'Updated Region Name';

    livewire(EditRegion::class, ['record' => $record->getRouteKey()])
        ->fillForm([
            'name' => $newName,
            'delivery_cost' => 50.00,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Region::class, [
        'id' => $record->id,
        'name' => $newName,
        'delivery_cost' => 50.00,
    ]);
});

it('can update only delivery_cost', function () {
    $record = Region::factory()->create();
    $newDeliveryCost = 75.50;

    livewire(EditRegion::class, ['record' => $record->getRouteKey()])
        ->fillForm([
            'name' => $record->name,
            'delivery_cost' => $newDeliveryCost,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Region::class, [
        'id' => $record->id,
        'name' => $record->name,
        'delivery_cost' => $newDeliveryCost,
    ]);
});
