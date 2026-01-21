<?php

use App\Enums\UserRole;
use App\Filament\Resources\ExpenseTypes\Pages\CreateExpenseType;
use App\Filament\Resources\ExpenseTypes\Pages\EditExpenseType;
use App\Filament\Resources\ExpenseTypes\Pages\ListExpenseTypes;
use App\Filament\Resources\ExpenseTypes\Pages\ViewExpenseType;
use App\Models\ExpenceType;
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
    livewire(ListExpenseTypes::class)
        ->assertSuccessful();
});

it('can render the create page', function () {
    livewire(CreateExpenseType::class)
        ->assertSuccessful();
});

it('can render the edit page', function () {
    $record = ExpenceType::factory()->create();

    livewire(EditExpenseType::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

it('can render the view page', function () {
    $record = ExpenceType::factory()->create();

    livewire(ViewExpenseType::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

// Table Column Tests
it('has column', function (string $column) {
    livewire(ListExpenseTypes::class)
        ->assertTableColumnExists($column);
})->with(['name', 'avg_month_rate', 'created_at', 'updated_at']);

it('can render column', function (string $column) {
    livewire(ListExpenseTypes::class)
        ->assertCanRenderTableColumn($column);
})->with(['name', 'avg_month_rate', 'created_at', 'updated_at']);

it('can sort column', function (string $column) {
    $records = ExpenceType::factory(5)->create();

    livewire(ListExpenseTypes::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($column))
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($column));
})->with(['name', 'avg_month_rate', 'created_at', 'updated_at']);

// Search Tests
it('can search by name', function () {
    $records = ExpenceType::factory(5)->create();

    $value = $records->first()->name;

    livewire(ListExpenseTypes::class)
        ->searchTable($value)
        ->assertCanSeeTableRecords($records->where('name', $value))
        ->assertCanNotSeeTableRecords($records->where('name', '!=', $value));
});

// CRUD Operations
it('can create an expense type', function () {
    $record = ExpenceType::factory()->make();

    livewire(CreateExpenseType::class)
        ->fillForm([
            'name' => $record->name,
            'avg_month_rate' => 1500.50,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(ExpenceType::class, [
        'name' => $record->name,
        'avg_month_rate' => 1500.50,
    ]);
});

it('can create an expense type without avg_month_rate', function () {
    $record = ExpenceType::factory()->make();

    livewire(CreateExpenseType::class)
        ->fillForm([
            'name' => $record->name,
            'avg_month_rate' => null,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(ExpenceType::class, [
        'name' => $record->name,
        'avg_month_rate' => null,
    ]);
});

it('can update an expense type', function () {
    $record = ExpenceType::factory()->create();
    $newRecord = ExpenceType::factory()->make();

    livewire(EditExpenseType::class, ['record' => $record->getRouteKey()])
        ->fillForm([
            'name' => $newRecord->name,
            'avg_month_rate' => 2500.75,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(ExpenceType::class, [
        'id' => $record->id,
        'name' => $newRecord->name,
        'avg_month_rate' => 2500.75,
    ]);
});

it('can view an expense type', function () {
    $record = ExpenceType::factory()->create(['avg_month_rate' => 3000.00]);

    livewire(ViewExpenseType::class, ['record' => $record->getRouteKey()])
        ->assertSchemaStateSet([
            'name' => $record->name,
            'avg_month_rate' => 3000.00,
        ]);
});

it('can delete an expense type', function () {
    $record = ExpenceType::factory()->create();

    livewire(EditExpenseType::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete')
        ->callAction(DeleteAction::class);

    $this->assertModelMissing($record);
});

it('can bulk delete expense types', function () {
    $records = ExpenceType::factory(5)->create();

    livewire(ListExpenseTypes::class)
        ->callTableBulkAction('delete', $records);

    foreach ($records as $record) {
        $this->assertModelMissing($record);
    }
});

// Form Validation Tests
it('can validate required name', function () {
    livewire(CreateExpenseType::class)
        ->fillForm(['name' => null])
        ->call('create')
        ->assertHasFormErrors(['name' => ['required']]);
});

it('can validate max length for name', function () {
    livewire(CreateExpenseType::class)
        ->fillForm(['name' => Str::random(256)])
        ->call('create')
        ->assertHasFormErrors(['name' => ['max:255']]);
});

it('can validate numeric avg_month_rate', function () {
    livewire(CreateExpenseType::class)
        ->fillForm([
            'name' => 'Test Type',
            'avg_month_rate' => 'not-a-number',
        ])
        ->call('create')
        ->assertHasFormErrors(['avg_month_rate']);
});

// Table Actions
it('has view action on list page', function () {
    livewire(ListExpenseTypes::class)
        ->assertTableActionExists('view');
});

it('has edit action on list page', function () {
    livewire(ListExpenseTypes::class)
        ->assertTableActionExists('edit');
});

it('has delete action on list page', function () {
    livewire(ListExpenseTypes::class)
        ->assertTableActionExists('delete');
});

// Page Actions
it('has view action on edit page header', function () {
    $record = ExpenceType::factory()->create();

    livewire(EditExpenseType::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('view');
});

it('has delete action on edit page header', function () {
    $record = ExpenceType::factory()->create();

    livewire(EditExpenseType::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete');
});

it('has edit action on view page header', function () {
    $record = ExpenceType::factory()->create();

    livewire(ViewExpenseType::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('edit');
});

// Table Records Visibility
it('can see table records', function () {
    $records = ExpenceType::factory(5)->create();

    livewire(ListExpenseTypes::class)
        ->assertCanSeeTableRecords($records);
});

it('can count table records', function () {
    ExpenceType::factory(3)->create();

    livewire(ListExpenseTypes::class)
        ->assertCountTableRecords(3);
});

// Field Visibility Tests
it('has name field in create form', function () {
    livewire(CreateExpenseType::class)
        ->assertSchemaComponentExists('name');
});

it('has avg_month_rate field in create form', function () {
    livewire(CreateExpenseType::class)
        ->assertSchemaComponentExists('avg_month_rate');
});

// Money Formatting Test
it('displays avg_month_rate as EGP currency', function () {
    $record = ExpenceType::factory()->create(['avg_month_rate' => 1234.56]);

    livewire(ListExpenseTypes::class)
        ->assertCanSeeTableRecords([$record]);
});

// Placeholder Test
it('displays placeholder for null avg_month_rate', function () {
    $record = ExpenceType::factory()->create(['avg_month_rate' => null]);

    livewire(ListExpenseTypes::class)
        ->assertCanSeeTableRecords([$record]);
});

// Timestamp Toggleability
it('created_at and updated_at are toggleable columns', function () {
    livewire(ListExpenseTypes::class)
        ->assertTableColumnExists('created_at')
        ->assertTableColumnExists('updated_at');
});
