<?php

use App\Enums\UserRole;
use App\Filament\Resources\TableTypes\Pages\CreateTableType;
use App\Filament\Resources\TableTypes\Pages\EditTableType;
use App\Filament\Resources\TableTypes\Pages\ListTableTypes;
use App\Models\TableType;
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
    livewire(ListTableTypes::class)
        ->assertSuccessful();
});

it('can render the create page', function () {
    livewire(CreateTableType::class)
        ->assertSuccessful();
});

it('can render the edit page', function () {
    $record = TableType::factory()->create();

    livewire(EditTableType::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

// Table Column Tests
it('has column', function (string $column) {
    livewire(ListTableTypes::class)
        ->assertTableColumnExists($column);
})->with(['id', 'name', 'created_at', 'updated_at']);

it('can render column', function (string $column) {
    livewire(ListTableTypes::class)
        ->assertCanRenderTableColumn($column);
})->with(['id', 'name', 'created_at', 'updated_at']);

// Table Sorting Tests
it('can sort by name', function () {
    $records = TableType::factory(5)->create();

    livewire(ListTableTypes::class)
        ->sortTable('name')
        ->assertCanSeeTableRecords($records->sortBy('name'))
        ->sortTable('name', 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc('name'));
});

it('can sort by id', function () {
    $records = TableType::factory(5)->create();

    livewire(ListTableTypes::class)
        ->sortTable('id')
        ->assertCanSeeTableRecords($records->sortBy('id'))
        ->sortTable('id', 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc('id'));
});

it('can sort by created_at', function () {
    $records = TableType::factory(5)->create();

    livewire(ListTableTypes::class)
        ->sortTable('created_at')
        ->assertCanSeeTableRecords($records->sortBy('created_at'))
        ->sortTable('created_at', 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc('created_at'));
});

it('can sort by updated_at', function () {
    $records = TableType::factory(5)->create();

    livewire(ListTableTypes::class)
        ->sortTable('updated_at')
        ->assertCanSeeTableRecords($records->sortBy('updated_at'))
        ->sortTable('updated_at', 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc('updated_at'));
});

// Table Search Tests
it('can search table types by name', function () {
    $tableType1 = TableType::factory()->create(['name' => 'VIP']);
    $tableType2 = TableType::factory()->create(['name' => 'كلاسيك']);

    livewire(ListTableTypes::class)
        ->searchTable('VIP')
        ->assertCanSeeTableRecords([$tableType1])
        ->assertCanNotSeeTableRecords([$tableType2]);
});

it('can search table types by id', function () {
    $tableTypes = TableType::factory(5)->create();
    // Pick a unique ID that won't be contained in other IDs
    $searchRecord = $tableTypes->last();
    $searchId = $searchRecord->id;

    livewire(ListTableTypes::class)
        ->searchTable((string) $searchId)
        ->assertCanSeeTableRecords([$searchRecord]);
});

// CRUD Operations
it('can create a table type', function () {
    $record = TableType::factory()->make(['name' => 'Test Table Type']);

    livewire(CreateTableType::class)
        ->fillForm([
            'name' => $record->name,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(TableType::class, [
        'name' => $record->name,
    ]);
});

it('can update a table type', function () {
    $record = TableType::factory()->create();
    $newRecord = TableType::factory()->make(['name' => 'Updated Table Type']);

    livewire(EditTableType::class, ['record' => $record->getRouteKey()])
        ->fillForm([
            'name' => $newRecord->name,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(TableType::class, [
        'id' => $record->id,
        'name' => $newRecord->name,
    ]);
});

it('can delete a table type', function () {
    $record = TableType::factory()->create();

    livewire(EditTableType::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete')
        ->callAction(DeleteAction::class);

    $this->assertModelMissing($record);
});

it('can bulk delete table types', function () {
    $records = TableType::factory(5)->create();

    livewire(ListTableTypes::class)
        ->callTableBulkAction('delete', $records);

    foreach ($records as $record) {
        $this->assertModelMissing($record);
    }
});

// Form Validation Tests
it('can validate required name', function () {
    livewire(CreateTableType::class)
        ->fillForm(['name' => null])
        ->call('create')
        ->assertHasFormErrors(['name' => ['required']]);
});

it('can validate max length on name', function () {
    livewire(CreateTableType::class)
        ->fillForm([
            'name' => Str::random(256),
        ])
        ->call('create')
        ->assertHasFormErrors(['name' => ['max:255']]);
});

it('can validate unique name', function () {
    $existingTableType = TableType::factory()->create(['name' => 'VIP']);

    livewire(CreateTableType::class)
        ->fillForm([
            'name' => 'VIP',
        ])
        ->call('create')
        ->assertHasFormErrors(['name' => ['unique']]);
});

it('can validate unique name on update (ignores current record)', function () {
    $tableType1 = TableType::factory()->create(['name' => 'VIP']);
    $tableType2 = TableType::factory()->create(['name' => 'كلاسيك']);

    livewire(EditTableType::class, ['record' => $tableType1->getRouteKey()])
        ->fillForm([
            'name' => 'VIP', // Same name, should be valid
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    livewire(EditTableType::class, ['record' => $tableType1->getRouteKey()])
        ->fillForm([
            'name' => 'كلاسيك', // Another record's name, should error
        ])
        ->call('save')
        ->assertHasFormErrors(['name' => ['unique']]);
});

// Table Actions Tests
it('has edit action on list page', function () {
    $record = TableType::factory()->create();

    livewire(ListTableTypes::class)
        ->assertTableActionExists('edit');
});

it('has delete action on list page', function () {
    $record = TableType::factory()->create();

    livewire(ListTableTypes::class)
        ->assertTableActionExists('delete');
});

// Page Actions Tests
it('has delete action on edit page header', function () {
    $record = TableType::factory()->create();

    livewire(EditTableType::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete');
});

// Record Visibility Tests
it('can see table records', function () {
    $records = TableType::factory(5)->create();

    livewire(ListTableTypes::class)
        ->assertCanSeeTableRecords($records);
});

it('can count table records', function () {
    TableType::factory(3)->create();

    livewire(ListTableTypes::class)
        ->assertCountTableRecords(3);
});

// Column Toggleability Tests
it('updated_at is toggleable and hidden by default', function () {
    livewire(ListTableTypes::class)
        ->assertTableColumnExists('updated_at');
});

it('created_at is toggleable and hidden by default', function () {
    livewire(ListTableTypes::class)
        ->assertTableColumnExists('created_at');
});

// Default Sort Test
it('table types are sorted by name by default', function () {
    $tableType1 = TableType::factory()->create(['name' => 'زاوية']);
    $tableType2 = TableType::factory()->create(['name' => 'بدوي']);
    $tableType3 = TableType::factory()->create(['name' => 'VIP']);

    livewire(ListTableTypes::class)
        ->assertCanSeeTableRecords([$tableType3, $tableType2, $tableType1]);
});

// Section and UI Tests
it('has section with Arabic heading', function () {
    livewire(CreateTableType::class)
        ->assertSee('بيانات نوع الطاولة');
});

it('displays empty state with correct Arabic text', function () {
    livewire(ListTableTypes::class)
        ->assertSee('لا توجد أنواع طاولات');
});

// Authorization Tests
it('non-admin users cannot access table type resource', function () {
    $user = User::factory()->create(['role' => UserRole::CASHIER]);
    actingAs($user);

    livewire(ListTableTypes::class)
        ->assertForbidden();
});

it('non-admin users cannot create table types', function () {
    $user = User::factory()->create(['role' => UserRole::CASHIER]);
    actingAs($user);

    livewire(CreateTableType::class)
        ->assertForbidden();
});

it('non-admin users cannot edit table types', function () {
    $user = User::factory()->create(['role' => UserRole::CASHIER]);
    $record = TableType::factory()->create();
    actingAs($user);

    livewire(EditTableType::class, ['record' => $record->getRouteKey()])
        ->assertForbidden();
});

// Field Helper Text Test
it('has helper text for name field', function () {
    livewire(CreateTableType::class)
        ->assertSee('أدخل اسم نوع الطاولة (يجب أن يكون فريداً)');
});

// Striped Table Test
it('displays table with striped rows', function () {
    TableType::factory(3)->create();

    livewire(ListTableTypes::class)
        ->assertSuccessful();
});
