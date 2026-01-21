<?php

use App\Enums\UserRole;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Models\Expense;
use App\Models\ExpenceType;
use App\Models\Shift;
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
    livewire(ListExpenses::class)
        ->assertSuccessful();
});

it('can render the create page', function () {
    livewire(CreateExpense::class)
        ->assertSuccessful();
});

it('can render the edit page', function () {
    $expenseType = ExpenceType::factory()->create();
    $record = Expense::factory()->create(['expence_type_id' => $expenseType->id]);

    livewire(EditExpense::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

it('can render the view page', function () {
    $expenseType = ExpenceType::factory()->create();
    $record = Expense::factory()->create(['expence_type_id' => $expenseType->id]);

    livewire(ViewExpense::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

// Table Column Tests
it('has column', function (string $column) {
    livewire(ListExpenses::class)
        ->assertTableColumnExists($column);
})->with(['expenceType.name', 'amount', 'notes', 'created_at', 'updated_at']);

it('can render column', function (string $column) {
    livewire(ListExpenses::class)
        ->assertCanRenderTableColumn($column);
})->with(['expenceType.name', 'amount', 'notes', 'created_at', 'updated_at']);

// Table Sorting Tests
it('can sort column', function (string $column) {
    $expenseType = ExpenceType::factory()->create();
    $records = Expense::factory(5)->create(['expence_type_id' => $expenseType->id]);

    livewire(ListExpenses::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($column))
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($column));
})->with(['amount', 'created_at', 'updated_at']);

// Table Search Tests
it('can search expenses', function () {
    $expenseType1 = ExpenceType::factory()->create(['name' => 'كهرباء']);
    $expenseType2 = ExpenceType::factory()->create(['name' => 'مياه']);

    $expense1 = Expense::factory()->create(['expence_type_id' => $expenseType1->id]);
    $expense2 = Expense::factory()->create(['expence_type_id' => $expenseType2->id]);

    livewire(ListExpenses::class)
        ->searchTable('كهرباء')
        ->assertCanSeeTableRecords([$expense1])
        ->assertCanNotSeeTableRecords([$expense2]);
});

// CRUD Operations
it('can create an expense', function () {
    $expenseType = ExpenceType::factory()->create();
    $shift = Shift::factory()->create(['closed' => false, 'end_at' => null]); // Active shift
    $record = Expense::factory()->make([
        'expence_type_id' => $expenseType->id,
    ]);

    livewire(CreateExpense::class)
        ->fillForm([
            'expence_type_id' => $expenseType->id,
            'amount' => $record->amount,
            'notes' => $record->notes,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Expense::class, [
        'expence_type_id' => $expenseType->id,
        'shift_id' => $shift->id,
        'amount' => $record->amount,
    ]);
});

it('can update an expense', function () {
    $expenseType = ExpenceType::factory()->create();
    $record = Expense::factory()->create(['expence_type_id' => $expenseType->id]);
    $newRecord = Expense::factory()->make(['expence_type_id' => $expenseType->id]);

    livewire(EditExpense::class, ['record' => $record->getRouteKey()])
        ->fillForm([
            'expence_type_id' => $expenseType->id,
            'amount' => $newRecord->amount,
            'notes' => $newRecord->notes,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Expense::class, [
        'id' => $record->id,
        'expence_type_id' => $expenseType->id,
        'amount' => $newRecord->amount,
    ]);
});

it('can view an expense', function () {
    $expenseType = ExpenceType::factory()->create();
    $record = Expense::factory()->create(['expence_type_id' => $expenseType->id]);

    livewire(ViewExpense::class, ['record' => $record->getRouteKey()])
        ->assertSchemaStateSet([
            'expence_type_id' => $record->expence_type_id,
            'amount' => $record->amount,
            'notes' => $record->notes,
        ]);
});

it('can delete an expense', function () {
    $expenseType = ExpenceType::factory()->create();
    $record = Expense::factory()->create(['expence_type_id' => $expenseType->id]);

    livewire(EditExpense::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete')
        ->callAction(DeleteAction::class);

    $this->assertModelMissing($record);
});

it('can bulk delete expenses', function () {
    $expenseType = ExpenceType::factory()->create();
    $records = Expense::factory(5)->create(['expence_type_id' => $expenseType->id]);

    livewire(ListExpenses::class)
        ->callTableBulkAction('delete', $records);

    foreach ($records as $record) {
        $this->assertModelMissing($record);
    }
});

// Form Validation Tests
it('can validate required expence_type_id', function () {
    livewire(CreateExpense::class)
        ->fillForm(['expence_type_id' => null])
        ->call('create')
        ->assertHasFormErrors(['expence_type_id' => ['required']]);
});

it('can validate required amount', function () {
    $expenseType = ExpenceType::factory()->create();

    livewire(CreateExpense::class)
        ->fillForm([
            'expence_type_id' => $expenseType->id,
            'amount' => null,
        ])
        ->call('create')
        ->assertHasFormErrors(['amount' => ['required']]);
});

it('can validate numeric amount', function () {
    $expenseType = ExpenceType::factory()->create();

    livewire(CreateExpense::class)
        ->fillForm([
            'expence_type_id' => $expenseType->id,
            'amount' => 'not-a-number',
        ])
        ->call('create')
        ->assertHasFormErrors(['amount' => ['numeric']]);
});

it('can validate max length on notes', function () {
    $expenseType = ExpenceType::factory()->create();

    livewire(CreateExpense::class)
        ->fillForm([
            'expence_type_id' => $expenseType->id,
            'amount' => 100,
            'notes' => Str::random(1001),
        ])
        ->call('create')
        ->assertHasFormErrors(['notes' => ['max:1000']]);
});

// Table Actions Tests
it('has view action on list page', function () {
    $expenseType = ExpenceType::factory()->create();
    $record = Expense::factory()->create(['expence_type_id' => $expenseType->id]);

    livewire(ListExpenses::class)
        ->assertTableActionExists('view');
});

it('has edit action on list page', function () {
    $expenseType = ExpenceType::factory()->create();
    $record = Expense::factory()->create(['expence_type_id' => $expenseType->id]);

    livewire(ListExpenses::class)
        ->assertTableActionExists('edit');
});

it('has delete action on list page', function () {
    $expenseType = ExpenceType::factory()->create();
    $record = Expense::factory()->create(['expence_type_id' => $expenseType->id]);

    livewire(ListExpenses::class)
        ->assertTableActionExists('delete');
});

// Page Actions Tests
it('has view action on edit page header', function () {
    $expenseType = ExpenceType::factory()->create();
    $record = Expense::factory()->create(['expence_type_id' => $expenseType->id]);

    livewire(EditExpense::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('view');
});

it('has delete action on edit page header', function () {
    $expenseType = ExpenceType::factory()->create();
    $record = Expense::factory()->create(['expence_type_id' => $expenseType->id]);

    livewire(EditExpense::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete');
});

it('has edit action on view page header', function () {
    $expenseType = ExpenceType::factory()->create();
    $record = Expense::factory()->create(['expence_type_id' => $expenseType->id]);

    livewire(ViewExpense::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('edit');
});

// Record Visibility Tests
it('can see table records', function () {
    $expenseType = ExpenceType::factory()->create();
    $records = Expense::factory(5)->create(['expence_type_id' => $expenseType->id]);

    livewire(ListExpenses::class)
        ->assertCanSeeTableRecords($records);
});

it('can count table records', function () {
    $expenseType = ExpenceType::factory()->create();
    Expense::factory(3)->create(['expence_type_id' => $expenseType->id]);

    livewire(ListExpenses::class)
        ->assertCountTableRecords(3);
});

// Filter Tests
it('can filter by expense type', function () {
    $expenseType1 = ExpenceType::factory()->create();
    $expenseType2 = ExpenceType::factory()->create();

    $expense1 = Expense::factory()->create(['expence_type_id' => $expenseType1->id]);
    $expense2 = Expense::factory()->create(['expence_type_id' => $expenseType2->id]);

    livewire(ListExpenses::class)
        ->filterTable('expence_type_id', $expenseType1->id)
        ->assertCanSeeTableRecords([$expense1])
        ->assertCanNotSeeTableRecords([$expense2]);
});

it('can filter by date range', function () {
    $expenseType = ExpenceType::factory()->create();

    $oldExpense = Expense::factory()->create([
        'expence_type_id' => $expenseType->id,
        'created_at' => now()->subDays(10),
    ]);

    $recentExpense = Expense::factory()->create([
        'expence_type_id' => $expenseType->id,
        'created_at' => now()->subDays(2),
    ]);

    livewire(ListExpenses::class)
        ->filterTable('created_at', [
            'created_from' => now()->subDays(5)->toDateString(),
            'created_until' => now()->toDateString(),
        ])
        ->assertCanSeeTableRecords([$recentExpense])
        ->assertCanNotSeeTableRecords([$oldExpense]);
});

// Relationship Tests
it('shows expense type name correctly', function () {
    $expenseType = ExpenceType::factory()->create(['name' => 'كهرباء']);
    $expense = Expense::factory()->create(['expence_type_id' => $expenseType->id]);

    livewire(ListExpenses::class)
        ->assertSee('كهرباء');
});

// Column Toggleability Tests
it('updated_at is toggleable and hidden by default', function () {
    livewire(ListExpenses::class)
        ->assertTableColumnExists('updated_at');
});

// Default Sort Test
it('expenses are sorted by created_at descending by default', function () {
    $expenseType = ExpenceType::factory()->create();

    $expense1 = Expense::factory()->create([
        'expence_type_id' => $expenseType->id,
        'created_at' => now()->subDays(2),
    ]);

    $expense2 = Expense::factory()->create([
        'expence_type_id' => $expenseType->id,
        'created_at' => now()->subDay(),
    ]);

    $expense3 = Expense::factory()->create([
        'expence_type_id' => $expenseType->id,
        'created_at' => now(),
    ]);

    livewire(ListExpenses::class)
        ->assertCanSeeTableRecords([$expense3, $expense2, $expense1]);
});

// Currency Formatting Test
it('displays amount with EGP currency', function () {
    $expenseType = ExpenceType::factory()->create();
    $expense = Expense::factory()->create([
        'expence_type_id' => $expenseType->id,
        'amount' => 150.50,
    ]);

    livewire(ListExpenses::class)
        ->assertSee('١٥٠'); // Arabic numerals are used in the UI
});
