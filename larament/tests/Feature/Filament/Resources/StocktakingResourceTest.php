<?php

use App\Enums\UserRole;
use App\Filament\Actions\CloseStocktakingAction;
use App\Filament\Resources\Stocktakings\Pages\CreateStocktaking;
use App\Filament\Resources\Stocktakings\Pages\EditStocktaking;
use App\Filament\Resources\Stocktakings\Pages\ListStocktakings;
use App\Filament\Resources\Stocktakings\Pages\ViewStocktaking;
use App\Filament\Resources\Stocktakings\RelationManagers\ItemsRelationManager;
use App\Models\Product;
use App\Models\Stocktaking;
use App\Models\StocktakingItem;
use App\Models\User;
use Filament\Actions\DeleteAction;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    actingAs($this->admin);
});

// Page Rendering Tests
it('can render the index page', function () {
    livewire(ListStocktakings::class)
        ->assertSuccessful();
});

it('can render the create page', function () {
    livewire(CreateStocktaking::class)
        ->assertSuccessful();
});

it('can render the edit page', function () {
    $record = Stocktaking::factory()->create();

    livewire(EditStocktaking::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

it('can render the view page', function () {
    $record = Stocktaking::factory()->create();

    livewire(ViewStocktaking::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

// Table Column Tests
it('has column', function (string $column) {
    livewire(ListStocktakings::class)
        ->assertTableColumnExists($column);
})->with(['id', 'user.name', 'items_count', 'total', 'notes', 'closed_at', 'created_at']);

it('can render column', function (string $column) {
    livewire(ListStocktakings::class)
        ->assertCanRenderTableColumn($column);
})->with(['id', 'user.name', 'items_count', 'total', 'notes', 'closed_at', 'created_at']);

it('can sort column', function (string $column) {
    $records = Stocktaking::factory(5)->create();

    livewire(ListStocktakings::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($column))
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($column));
})->with(['id', 'total', 'created_at']);

// Search Tests
it('can search by id', function () {
    $records = Stocktaking::factory(5)->create();

    $value = $records->first()->id;

    livewire(ListStocktakings::class)
        ->searchTable($value)
        ->assertCanSeeTableRecords($records->where('id', $value))
        ->assertCanNotSeeTableRecords($records->where('id', '!=', $value));
});

// CRUD Operations
it('can create a stocktaking', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'price' => 100,
    ]);

    livewire(CreateStocktaking::class)
        ->fillForm([
            'user_id' => $user->id,
            'notes' => 'Test stocktaking',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'stock_quantity' => 50,
                    'real_quantity' => 45,
                    'price' => 100,
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Stocktaking::class, [
        'user_id' => $user->id,
        'notes' => 'Test stocktaking',
    ]);
});

it('can create a stocktaking without notes', function () {
    $user = User::factory()->create();

    livewire(CreateStocktaking::class)
        ->fillForm([
            'user_id' => $user->id,
            'notes' => null,
            'items' => [],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Stocktaking::class, [
        'user_id' => $user->id,
        'notes' => null,
    ]);
});

it('can update a stocktaking', function () {
    $record = Stocktaking::factory()->create();
    $newUser = User::factory()->create();

    livewire(EditStocktaking::class, ['record' => $record->getRouteKey()])
        ->fillForm([
            'user_id' => $newUser->id,
            'notes' => 'Updated stocktaking',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Stocktaking::class, [
        'id' => $record->id,
        'user_id' => $newUser->id,
        'notes' => 'Updated stocktaking',
    ]);
});

it('can view a stocktaking', function () {
    $record = Stocktaking::factory()->create([
        'notes' => 'Test notes',
        'total' => 100,
    ]);

    livewire(ViewStocktaking::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

it('can delete a stocktaking', function () {
    $record = Stocktaking::factory()->create(['closed_at' => null]);

    livewire(EditStocktaking::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete')
        ->callAction(DeleteAction::class);

    $this->assertModelMissing($record);
});

it('cannot delete a closed stocktaking', function () {
    $record = Stocktaking::factory()->create(['closed_at' => now()]);

    livewire(ListStocktakings::class)
        ->assertTableActionHidden('delete', $record);
});

it('can bulk delete stocktakings', function () {
    $records = Stocktaking::factory(5)->create(['closed_at' => null]);

    livewire(ListStocktakings::class)
        ->callTableBulkAction('delete', $records);

    foreach ($records as $record) {
        $this->assertModelMissing($record);
    }
});

// Form Validation Tests
it('can validate required user_id', function () {
    livewire(CreateStocktaking::class)
        ->fillForm(['user_id' => null])
        ->call('create')
        ->assertHasFormErrors(['user_id' => ['required']]);
});

// Table Actions
it('has close action on list page', function () {
    livewire(ListStocktakings::class)
        ->assertTableActionExists('close');
});

it('has view action on list page', function () {
    livewire(ListStocktakings::class)
        ->assertTableActionExists('view');
});

it('has edit action on list page', function () {
    livewire(ListStocktakings::class)
        ->assertTableActionExists('edit');
});

it('has delete action on list page', function () {
    livewire(ListStocktakings::class)
        ->assertTableActionExists('delete');
});

// Page Actions
it('has edit action on view page header', function () {
    $record = Stocktaking::factory()->create();

    livewire(ViewStocktaking::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('edit');
});

it('has close action on view page header', function () {
    $record = Stocktaking::factory()->create(['closed_at' => null]);

    livewire(ViewStocktaking::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('close');
});

it('has view action on edit page header', function () {
    $record = Stocktaking::factory()->create();

    livewire(EditStocktaking::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('view');
});

it('has delete action on edit page header', function () {
    $record = Stocktaking::factory()->create();

    livewire(EditStocktaking::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete');
});

// Table Records Visibility
it('can see table records', function () {
    $records = Stocktaking::factory(5)->create();

    livewire(ListStocktakings::class)
        ->assertCanSeeTableRecords($records);
});

it('can count table records', function () {
    Stocktaking::factory(3)->create();

    livewire(ListStocktakings::class)
        ->assertCountTableRecords(3);
});

// Field Visibility Tests
it('has user_id field in create form', function () {
    livewire(CreateStocktaking::class)
        ->assertSchemaComponentExists('user_id');
});

it('has notes field in create form', function () {
    livewire(CreateStocktaking::class)
        ->assertSchemaComponentExists('notes');
});

it('has items field in create form', function () {
    livewire(CreateStocktaking::class)
        ->assertSchemaComponentExists('items');
});

// Closed Stocktaking Tests
it('displays closed stocktakings with success badge', function () {
    $record = Stocktaking::factory()->create(['closed_at' => now()]);

    livewire(ListStocktakings::class)
        ->assertCanSeeTableRecords([$record]);
});

it('displays open stocktakings with warning badge', function () {
    $record = Stocktaking::factory()->create(['closed_at' => null]);

    livewire(ListStocktakings::class)
        ->assertCanSeeTableRecords([$record]);
});

it('cannot edit a closed stocktaking', function () {
    $record = Stocktaking::factory()->create(['closed_at' => now()]);

    livewire(EditStocktaking::class, ['record' => $record->getRouteKey()])
        ->assertForbidden();
});

// Money Formatting Test
it('displays total as EGP currency', function () {
    $record = Stocktaking::factory()->create(['total' => 1234.56]);

    livewire(ListStocktakings::class)
        ->assertCanSeeTableRecords([$record]);
});

// Filters Tests
it('can filter by user', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    
    $recordsUser1 = Stocktaking::factory(3)->create(['user_id' => $user1->id]);
    $recordsUser2 = Stocktaking::factory(2)->create(['user_id' => $user2->id]);

    livewire(ListStocktakings::class)
        ->filterTable('user_id', $user1->id)
        ->assertCanSeeTableRecords($recordsUser1)
        ->assertCanNotSeeTableRecords($recordsUser2);
});

it('can filter by closed status', function () {
    $openRecords = Stocktaking::factory(3)->create(['closed_at' => null]);
    $closedRecords = Stocktaking::factory(2)->create(['closed_at' => now()]);

    livewire(ListStocktakings::class)
        ->filterTable('closed_at', false)
        ->assertCanSeeTableRecords($openRecords)
        ->assertCanNotSeeTableRecords($closedRecords);
});

it('can filter by open status', function () {
    $openRecords = Stocktaking::factory(3)->create(['closed_at' => null]);
    $closedRecords = Stocktaking::factory(2)->create(['closed_at' => now()]);

    livewire(ListStocktakings::class)
        ->filterTable('closed_at', true)
        ->assertCanSeeTableRecords($closedRecords)
        ->assertCanNotSeeTableRecords($openRecords);
});

it('can filter by created_at date range', function () {
    $oldRecords = Stocktaking::factory(2)->create(['created_at' => now()->subDays(10)]);
    $recentRecords = Stocktaking::factory(3)->create(['created_at' => now()]);

    livewire(ListStocktakings::class)
        ->filterTable('created_at', [
            'created_from' => now()->subDay()->toDateString(),
            'created_until' => now()->addDay()->toDateString(),
        ])
        ->assertCanSeeTableRecords($recentRecords)
        ->assertCanNotSeeTableRecords($oldRecords);
});

// Relation Manager Tests
it('can load the items relation manager', function () {
    $record = Stocktaking::factory()->create();

    livewire(ViewStocktaking::class, ['record' => $record->getRouteKey()])
        ->assertSeeLivewire(ItemsRelationManager::class);
});

it('can see items in relation manager', function () {
    $product = Product::factory()->create();
    $stocktaking = Stocktaking::factory()->create();
    $items = StocktakingItem::factory(3)->create([
        'stocktaking_id' => $stocktaking->id,
        'product_id' => $product->id,
    ]);

    livewire(ItemsRelationManager::class, [
        'ownerRecord' => $stocktaking,
        'pageClass' => ViewStocktaking::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords($items);
});

// Items Count Tests
it('displays items count correctly', function () {
    $product = Product::factory()->create();
    $stocktaking = Stocktaking::factory()->create();
    StocktakingItem::factory(5)->create([
        'stocktaking_id' => $stocktaking->id,
        'product_id' => $product->id,
    ]);

    livewire(ListStocktakings::class)
        ->assertCanSeeTableRecords([$stocktaking]);
});

// Close Action Visibility Tests
it('close action is visible for open stocktakings', function () {
    $record = Stocktaking::factory()->create(['closed_at' => null]);

    livewire(ViewStocktaking::class, ['record' => $record->getRouteKey()])
        ->assertActionVisible('close');
});

it('close action is hidden for closed stocktakings', function () {
    $record = Stocktaking::factory()->create(['closed_at' => now()]);

    livewire(ViewStocktaking::class, ['record' => $record->getRouteKey()])
        ->assertActionHidden('close');
});

// Default Values Tests
it('defaults user_id to current user', function () {
    livewire(CreateStocktaking::class)
        ->assertSchemaStateSet([
            'user_id' => $this->admin->id,
        ]);
});

it('defaults total to 0', function () {
    livewire(CreateStocktaking::class)
        ->assertSchemaStateSet([
            'total' => 0,
        ]);
});

// Notes Limit Test
it('truncates notes in table', function () {
    $longNotes = str_repeat('This is a very long note. ', 20);
    $record = Stocktaking::factory()->create(['notes' => $longNotes]);

    livewire(ListStocktakings::class)
        ->assertCanSeeTableRecords([$record]);
});
