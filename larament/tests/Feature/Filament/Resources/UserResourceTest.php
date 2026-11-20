<?php

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
});

it('can render the index page', function () {
    livewire(ListUsers::class)
        ->assertSuccessful();
});

it('can render the create page', function () {
    livewire(CreateUser::class)
        ->assertSuccessful();
});

it('can render the edit page', function () {
    $record = User::factory()->create();

    livewire(EditUser::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

it('has column', function (string $column) {
    livewire(ListUsers::class)
        ->assertTableColumnExists($column);
})->with(['name', 'email', 'roles.name', 'created_at', 'updated_at']);

it('can render column', function (string $column) {
    livewire(ListUsers::class)
        ->assertCanRenderTableColumn($column);
})->with(['name', 'email', 'roles.name']);

it('can sort column', function (string $column) {
    $records = User::factory(5)->create();

    livewire(ListUsers::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($column))
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($column));
})->with(['name', 'email']);

it('can search column', function (string $column) {
    $records = User::factory(5)->create();

    $value = $records->first()->{$column};

    livewire(ListUsers::class)
        ->searchTable($value)
        ->assertCanSeeTableRecords($records->where($column, $value))
        ->assertCanNotSeeTableRecords($records->where($column, '!=', $value));
})->with(['name', 'email']);

it('can create a record', function () {
    $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $record = User::factory()->make();

    livewire(CreateUser::class)
        ->fillForm([
            'name' => $record->name,
            'email' => $record->email,
            'password' => 'secret123',
            'passwordConfirmation' => 'secret123',
            'roles' => [$role->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(User::class, [
        'name' => $record->name,
        'email' => $record->email,
    ]);
});

it('can update a record', function () {
    $record = User::factory()->create();
    $newRecord = User::factory()->make();

    livewire(EditUser::class, ['record' => $record->getRouteKey()])
        ->fillForm([
            'name' => $newRecord->name,
            'email' => $newRecord->email,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(User::class, [
        'name' => $newRecord->name,
        'email' => $newRecord->email,
    ]);
});

it('can delete a record', function () {
    $record = User::factory()->create();

    livewire(EditUser::class, ['record' => $record->getRouteKey()])
        ->callAction(DeleteAction::class);

    $this->assertSoftDeleted($record);
});

it('can bulk delete records', function () {
    $records = User::factory(5)->create();

    livewire(ListUsers::class)
        ->callTableBulkAction('delete', $records);

    foreach ($records as $record) {
        $this->assertSoftDeleted($record);
    }
});

it('can validate required', function (string $column) {
    livewire(CreateUser::class)
        ->fillForm([$column => null])
        ->call('create')
        ->assertHasFormErrors([$column => ['required']]);
})->with(['name', 'email']);

it('can validate unique', function (string $column) {
    $record = User::factory()->create();

    livewire(CreateUser::class)
        ->fillForm(['email' => $record->email])
        ->call('create')
        ->assertHasFormErrors([$column => ['unique']]);
})->with(['email']);

it('can validate email', function (string $column) {
    livewire(CreateUser::class)
        ->fillForm(['email' => Str::random()])
        ->call('create')
        ->assertHasFormErrors([$column => ['email']]);
})->with(['email']);

it('can validate max length', function (string $column) {
    livewire(CreateUser::class)
        ->fillForm([$column => Str::random(256)])
        ->call('create')
        ->assertHasFormErrors([$column => ['max:255']]);
})->with(['name', 'email']);

it('can validate password confirmation', function () {
    livewire(CreateUser::class)
        ->fillForm([
            'password' => 'secret123',
            'passwordConfirmation' => 'different123',
        ])
        ->call('create')
        ->assertHasFormErrors(['password' => ['same']]);
});
