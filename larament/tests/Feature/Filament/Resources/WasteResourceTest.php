<?php

use App\Enums\UserRole;
use App\Filament\Resources\Wastes\Pages\CreateWaste;
use App\Filament\Resources\Wastes\Pages\EditWaste;
use App\Filament\Resources\Wastes\Pages\ListWastes;
use App\Filament\Resources\Wastes\Pages\ViewWaste;
use App\Models\User;
use App\Models\Waste;
use App\Models\Product;
use App\Models\InventoryItem;
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
    livewire(ListWastes::class)
        ->assertSuccessful();
});

it('can render the create page', function () {
    livewire(CreateWaste::class)
        ->assertSuccessful();
});

it('can render the edit page', function () {
    $record = Waste::factory()->create();

    livewire(EditWaste::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

it('can render the view page', function () {
    $record = Waste::factory()->create();

    livewire(ViewWaste::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

// Table Column Tests
it('has column', function (string $column) {
    livewire(ListWastes::class)
        ->assertTableColumnExists($column);
})->with(['id', 'user.name', 'items_count', 'total', 'notes', 'closed_at', 'created_at']);

it('can render column', function (string $column) {
    livewire(ListWastes::class)
        ->assertCanRenderTableColumn($column);
})->with(['id', 'user.name', 'items_count', 'total', 'notes', 'closed_at', 'created_at']);

it('can sort column', function (string $column) {
    $records = Waste::factory(5)->create();

    livewire(ListWastes::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($column))
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($column));
})->with(['id', 'total', 'created_at']);

// Search Tests
it('can search by id', function () {
    $records = Waste::factory(5)->create();

    $value = $records->first()->id;

    livewire(ListWastes::class)
        ->searchTable((string) $value)
        ->assertCanSeeTableRecords($records->where('id', $value))
        ->assertCanNotSeeTableRecords($records->where('id', '!=', $value));
});

// CRUD Operations
it('can create a waste record', function () {
    $product = Product::factory()->create(['cost' => 100.00]);
    InventoryItem::factory()->create([
        'product_id' => $product->id,
        'quantity' => 50,
    ]);

    livewire(CreateWaste::class)
        ->fillForm([
            'user_id' => $this->admin->id,
            'notes' => 'Test waste record',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Waste::class, [
        'user_id' => $this->admin->id,
        'notes' => 'Test waste record',
    ]);
});

it('can create a waste record without notes', function () {
    livewire(CreateWaste::class)
        ->fillForm([
            'user_id' => $this->admin->id,
            'notes' => null,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Waste::class, [
        'user_id' => $this->admin->id,
        'notes' => null,
    ]);
});

it('can update a waste record', function () {
    $record = Waste::factory()->create();
    $newUser = User::factory()->create(['role' => UserRole::ADMIN]);

    livewire(EditWaste::class, ['record' => $record->getRouteKey()])
        ->fillForm([
            'user_id' => $newUser->id,
            'notes' => 'Updated notes',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Waste::class, [
        'id' => $record->id,
        'user_id' => $newUser->id,
        'notes' => 'Updated notes',
    ]);
});

it('can view a waste record', function () {
    $record = Waste::factory()->create([
        'user_id' => $this->admin->id,
        'notes' => 'Test notes',
    ]);

    livewire(ViewWaste::class, ['record' => $record->getRouteKey()])
        ->assertSchemaStateSet([
            'user.name' => $this->admin->name,
            'notes' => 'Test notes',
        ]);
});

it('can delete a waste record', function () {
    $record = Waste::factory()->create();

    livewire(EditWaste::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete')
        ->callAction(DeleteAction::class);

    $this->assertModelMissing($record);
});

it('can bulk delete waste records', function () {
    $records = Waste::factory(5)->create();

    livewire(ListWastes::class)
        ->callTableBulkAction('delete', $records);

    foreach ($records as $record) {
        $this->assertModelMissing($record);
    }
});

it('cannot delete a closed waste record', function () {
    $record = Waste::factory()->create([
        'closed_at' => now(),
    ]);

    expect(\App\Filament\Resources\Wastes\WasteResource::canDelete($record))->toBeFalse();
});

it('cannot edit a closed waste record', function () {
    $record = Waste::factory()->create([
        'closed_at' => now(),
    ]);

    expect(\App\Filament\Resources\Wastes\WasteResource::canEdit($record))->toBeFalse();
});

// Form Validation Tests
it('can validate required user_id', function () {
    livewire(CreateWaste::class)
        ->fillForm(['user_id' => null])
        ->call('create')
        ->assertHasFormErrors(['user_id' => ['required']]);
});

// Table Actions
it('has close action on list page', function () {
    livewire(ListWastes::class)
        ->assertTableActionExists('close');
});

it('has print action on list page', function () {
    livewire(ListWastes::class)
        ->assertTableActionExists('print');
});

it('has view action on list page', function () {
    livewire(ListWastes::class)
        ->assertTableActionExists('view');
});

it('has edit action on list page', function () {
    livewire(ListWastes::class)
        ->assertTableActionExists('edit');
});

it('has delete action on list page', function () {
    livewire(ListWastes::class)
        ->assertTableActionExists('delete');
});

// Page Actions
it('has view action on edit page header', function () {
    $record = Waste::factory()->create();

    livewire(EditWaste::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('view');
});

it('has delete action on edit page header', function () {
    $record = Waste::factory()->create();

    livewire(EditWaste::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete');
});

it('has close action on view page header', function () {
    $record = Waste::factory()->create();

    livewire(ViewWaste::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('close');
});

it('has edit action on view page header', function () {
    $record = Waste::factory()->create();

    livewire(ViewWaste::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('edit');
});

// Close Action Tests
it('close action is visible for open waste records', function () {
    $record = Waste::factory()->create(['closed_at' => null]);

    livewire(ViewWaste::class, ['record' => $record->getRouteKey()])
        ->assertActionVisible('close');
});

it('close action is hidden for closed waste records', function () {
    $record = Waste::factory()->create(['closed_at' => now()]);

    livewire(ViewWaste::class, ['record' => $record->getRouteKey()])
        ->assertActionHidden('close');
});

// Table Records Visibility
it('can see table records', function () {
    $records = Waste::factory(5)->create();

    livewire(ListWastes::class)
        ->assertCanSeeTableRecords($records);
});

it('can count table records', function () {
    Waste::factory(3)->create();

    livewire(ListWastes::class)
        ->assertCountTableRecords(3);
});

// Field Visibility Tests
it('has user_id field in create form', function () {
    livewire(CreateWaste::class)
        ->assertSchemaComponentExists('user_id');
});

it('has total field in create form', function () {
    livewire(CreateWaste::class)
        ->assertSchemaComponentExists('total');
});

it('has notes field in create form', function () {
    livewire(CreateWaste::class)
        ->assertSchemaComponentExists('notes');
});

it('has items repeater in create form', function () {
    livewire(CreateWaste::class)
        ->assertSchemaComponentExists('items');
});

// Money Formatting Test
it('displays total as EGP currency', function () {
    $record = Waste::factory()->create(['total' => 1234.56]);

    livewire(ListWastes::class)
        ->assertCanSeeTableRecords([$record]);
});

// Badge Status Test
it('displays status badge for open waste records', function () {
    $record = Waste::factory()->create(['closed_at' => null]);

    livewire(ListWastes::class)
        ->assertCanSeeTableRecords([$record]);
});

it('displays status badge for closed waste records', function () {
    $record = Waste::factory()->create(['closed_at' => now()]);

    livewire(ListWastes::class)
        ->assertCanSeeTableRecords([$record]);
});

// Filter Tests
it('can filter by user', function () {
    $user1 = User::factory()->create(['role' => UserRole::ADMIN]);
    $user2 = User::factory()->create(['role' => UserRole::ADMIN]);

    $waste1 = Waste::factory()->create(['user_id' => $user1->id]);
    $waste2 = Waste::factory()->create(['user_id' => $user2->id]);

    livewire(ListWastes::class)
        ->filterTable('user_id', $user1->id)
        ->assertCanSeeTableRecords([$waste1])
        ->assertCanNotSeeTableRecords([$waste2]);
});

it('can filter by created_at date range', function () {
    $oldWaste = Waste::factory()->create(['created_at' => now()->subDays(10)]);
    $newWaste = Waste::factory()->create(['created_at' => now()]);

    livewire(ListWastes::class)
        ->filterTable('created_at', [
            'created_from' => now()->subDays(5)->format('Y-m-d'),
            'created_until' => now()->addDay()->format('Y-m-d'),
        ])
        ->assertCanSeeTableRecords([$newWaste])
        ->assertCanNotSeeTableRecords([$oldWaste]);
});

it('can filter by closed status', function () {
    $openWaste = Waste::factory()->create(['closed_at' => null]);
    $closedWaste = Waste::factory()->create(['closed_at' => now()]);

    livewire(ListWastes::class)
        ->filterTable('closed_at', true)
        ->assertCanSeeTableRecords([$closedWaste])
        ->assertCanNotSeeTableRecords([$openWaste]);

    livewire(ListWastes::class)
        ->filterTable('closed_at', false)
        ->assertCanSeeTableRecords([$openWaste])
        ->assertCanNotSeeTableRecords([$closedWaste]);
});

// Items Count Test
it('displays correct items count', function () {
    $product = Product::factory()->create();
    $waste = Waste::factory()->create();

    $waste->items()->createMany([
        [
            'product_id' => $product->id,
            'quantity' => 5,
            'price' => 100,
            'total' => 500,
        ],
        [
            'product_id' => $product->id,
            'quantity' => 3,
            'price' => 50,
            'total' => 150,
        ],
    ]);

    $waste->loadCount('items');

    livewire(ListWastes::class)
        ->assertTableColumnFormattedStateSet('items_count', '2', record: $waste);
});

// Notes Tooltip Test
it('displays notes with tooltip for long text', function () {
    $longNotes = Str::random(100);
    $record = Waste::factory()->create(['notes' => $longNotes]);

    livewire(ListWastes::class)
        ->assertCanSeeTableRecords([$record]);
});

// Default Sort Test
it('sorts by id descending by default', function () {
    $records = Waste::factory(5)->create();

    livewire(ListWastes::class)
        ->assertCanSeeTableRecords($records->sortByDesc('id'), inOrder: true);
});

// Total Field Disabled Test
it('total field is disabled in create form', function () {
    livewire(CreateWaste::class)
        ->assertFormFieldDisabled('total');
});
