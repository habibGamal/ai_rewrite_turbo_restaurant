<?php

use App\Filament\Resources\KitchenStations\Pages\CreateKitchenStation;
use App\Filament\Resources\KitchenStations\Pages\EditKitchenStation;
use App\Filament\Resources\KitchenStations\Pages\ListKitchenStations;
use App\Models\Category;
use App\Models\KitchenStation;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
});

// Page Rendering Tests
it('can render the index page', function () {
    livewire(ListKitchenStations::class)
        ->assertSuccessful();
});

it('can render the create page', function () {
    livewire(CreateKitchenStation::class)
        ->assertSuccessful();
});

it('can render the edit page', function () {
    $record = KitchenStation::factory()->create();

    livewire(EditKitchenStation::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

// Access Tests (same policy gating as Printers)
it('forbids access without the view permission', function () {
    $user = User::factory()->cashier()->create();
    actingAs($user);

    livewire(ListKitchenStations::class)
        ->assertForbidden();
});

// Table Column Tests
it('has column', function (string $column) {
    livewire(ListKitchenStations::class)
        ->assertTableColumnExists($column);
})->with(['name', 'is_active', 'sort_order', 'categories_count', 'created_at', 'updated_at']);

it('can render column', function (string $column) {
    KitchenStation::factory()->create();

    livewire(ListKitchenStations::class)
        ->assertCanRenderTableColumn($column);
})->with(['name', 'is_active', 'sort_order', 'categories_count']);

// Default Sorting Test
it('sorts by sort_order ascending by default', function () {
    KitchenStation::factory()->create([
        'name' => 'المحطة الثالثة',
        'sort_order' => 3,
    ]);
    KitchenStation::factory()->create([
        'name' => 'المحطة الأولى',
        'sort_order' => 1,
    ]);
    KitchenStation::factory()->create([
        'name' => 'المحطة الثانية',
        'sort_order' => 2,
    ]);

    livewire(ListKitchenStations::class)
        ->assertCanSeeTableRecords(KitchenStation::orderBy('sort_order')->get(), inOrder: true);
});

// Categories Count Test
it('shows correct categories count', function () {
    $station = KitchenStation::factory()->create();
    Category::factory()->count(3)->create(['kitchen_station_id' => $station->id]);

    livewire(ListKitchenStations::class)
        ->assertSee($station->name)
        ->assertSee('3'); // Should see the count displayed
});

// CRUD Operations Tests
it('can create a kitchen station', function () {
    $record = KitchenStation::factory()->make();

    livewire(CreateKitchenStation::class)
        ->fillForm([
            'name' => $record->name,
            'is_active' => true,
            'sort_order' => 4,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(KitchenStation::class, [
        'name' => $record->name,
        'is_active' => true,
        'sort_order' => 4,
    ]);
});

it('defaults a new station to active with sort order 0', function () {
    livewire(CreateKitchenStation::class)
        ->fillForm([
            'name' => 'محطة جديدة',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(KitchenStation::class, [
        'name' => 'محطة جديدة',
        'is_active' => true,
        'sort_order' => 0,
    ]);
});

it('can update a kitchen station', function () {
    $record = KitchenStation::factory()->create();

    livewire(EditKitchenStation::class, ['record' => $record->getRouteKey()])
        ->fillForm([
            'name' => 'اسم جديد',
            'is_active' => false,
            'sort_order' => 9,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $record->refresh();
    expect($record->name)->toBe('اسم جديد');
    expect($record->is_active)->toBeFalse();
    expect($record->sort_order)->toBe(9);
});

it('can delete a kitchen station', function () {
    $record = KitchenStation::factory()->create();

    livewire(EditKitchenStation::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete')
        ->callAction(DeleteAction::class);

    $this->assertModelMissing($record);
});

it('can bulk delete kitchen stations', function () {
    $records = KitchenStation::factory(3)->create();

    livewire(ListKitchenStations::class)
        ->callTableBulkAction(DeleteBulkAction::class, $records);

    foreach ($records as $record) {
        $this->assertModelMissing($record);
    }
});

// Form Validation Tests
it('can validate required name', function () {
    livewire(CreateKitchenStation::class)
        ->fillForm(['name' => null])
        ->call('create')
        ->assertHasFormErrors(['name' => ['required']]);
});

it('can validate max length for name', function () {
    livewire(CreateKitchenStation::class)
        ->fillForm(['name' => Str::random(256)])
        ->call('create')
        ->assertHasFormErrors(['name' => ['max:255']]);
});

it('can see table records', function () {
    $records = KitchenStation::factory(5)->create();

    livewire(ListKitchenStations::class)
        ->assertCanSeeTableRecords($records);
});

it('can count table records', function () {
    KitchenStation::factory(3)->create();

    livewire(ListKitchenStations::class)
        ->assertCountTableRecords(3);
});

// Table Actions Tests
it('has edit action on list page', function () {
    KitchenStation::factory()->create();

    livewire(ListKitchenStations::class)
        ->assertTableActionExists('edit');
});

it('has delete action on list page', function () {
    KitchenStation::factory()->create();

    livewire(ListKitchenStations::class)
        ->assertTableActionExists('delete');
});

it('has create action on list page header', function () {
    livewire(ListKitchenStations::class)
        ->assertActionExists('create');
});

// Form Field Tests
it('has name field', function () {
    livewire(CreateKitchenStation::class)
        ->assertFormFieldExists('name');
});

it('has is_active field', function () {
    livewire(CreateKitchenStation::class)
        ->assertFormFieldExists('is_active');
});

it('has sort_order field', function () {
    livewire(CreateKitchenStation::class)
        ->assertFormFieldExists('sort_order');
});
