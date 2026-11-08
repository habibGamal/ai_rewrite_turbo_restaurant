<?php

use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Categories\Pages\ViewCategory;
use App\Models\Category;
use App\Models\KitchenStation;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
});

it('can render the index page', function () {
    livewire(ListCategories::class)
        ->assertSuccessful();
});

it('can render the create page', function () {
    livewire(CreateCategory::class)
        ->assertSuccessful();
});

it('can render the edit page', function () {
    $record = Category::factory()->create();

    livewire(EditCategory::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

it('can render the view page', function () {
    $record = Category::factory()->create();

    livewire(ViewCategory::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

it('has column', function (string $column) {
    livewire(ListCategories::class)
        ->assertTableColumnExists($column);
})->with(['name', 'products_count', 'created_at', 'updated_at']);

it('can render column', function (string $column) {
    livewire(ListCategories::class)
        ->assertCanRenderTableColumn($column);
})->with(['name', 'products_count', 'created_at', 'updated_at']);

it('can sort column', function (string $column) {
    $records = Category::factory(5)->create();

    livewire(ListCategories::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($column))
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($column));
})->with(['name', 'created_at', 'updated_at']);

it('can search by name', function () {
    $records = Category::factory(5)->create();

    $value = $records->first()->name;

    livewire(ListCategories::class)
        ->searchTable($value)
        ->assertCanSeeTableRecords($records->where('name', $value))
        ->assertCanNotSeeTableRecords($records->where('name', '!=', $value));
});

it('can create a category', function () {
    $record = Category::factory()->make();

    livewire(CreateCategory::class)
        ->fillForm([
            'name' => $record->name,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Category::class, [
        'name' => $record->name,
    ]);
});

it('can update a category', function () {
    $record = Category::factory()->create();
    $newRecord = Category::factory()->make();

    livewire(EditCategory::class, ['record' => $record->getRouteKey()])
        ->fillForm([
            'name' => $newRecord->name,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Category::class, [
        'id' => $record->id,
        'name' => $newRecord->name,
    ]);
});

it('can view a category', function () {
    $record = Category::factory()->create();

    livewire(ViewCategory::class, ['record' => $record->getRouteKey()])
        ->assertSchemaStateSet([
            'name' => $record->name,
        ]);
});

it('can delete a category', function () {
    $record = Category::factory()->create();

    livewire(EditCategory::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete')
        ->callAction(DeleteAction::class);

    $this->assertModelMissing($record);
});

it('can bulk delete categories', function () {
    $records = Category::factory(5)->create();

    livewire(ListCategories::class)
        ->callTableBulkAction('delete', $records);

    foreach ($records as $record) {
        $this->assertModelMissing($record);
    }
});

it('can validate required name', function () {
    livewire(CreateCategory::class)
        ->fillForm(['name' => null])
        ->call('create')
        ->assertHasFormErrors(['name' => ['required']]);
});

it('can validate max length', function () {
    livewire(CreateCategory::class)
        ->fillForm(['name' => Str::random(256)])
        ->call('create')
        ->assertHasFormErrors(['name' => ['max:255']]);
});

it('shows correct products count', function () {
    $category = Category::factory()->create();
    Product::factory()->count(3)->create(['category_id' => $category->id]);

    livewire(ListCategories::class)
        ->assertSee($category->name)
        ->assertSee('3'); // Should see the count displayed
});

it('can see table records', function () {
    $records = Category::factory(5)->create();

    livewire(ListCategories::class)
        ->assertCanSeeTableRecords($records);
});

it('created_at and updated_at are toggleable', function () {
    livewire(ListCategories::class)
        ->assertTableColumnExists('created_at')
        ->assertTableColumnExists('updated_at');
});

it('has view action on list page', function () {
    $record = Category::factory()->create();

    livewire(ListCategories::class)
        ->assertTableActionExists('view');
});

it('has edit action on list page', function () {
    $record = Category::factory()->create();

    livewire(ListCategories::class)
        ->assertTableActionExists('edit');
});

it('has delete action on list page', function () {
    $record = Category::factory()->create();

    livewire(ListCategories::class)
        ->assertTableActionExists('delete');
});

it('has create action on list page header', function () {
    livewire(ListCategories::class)
        ->assertActionExists('create');
});

it('has view action on edit page header', function () {
    $record = Category::factory()->create();

    livewire(EditCategory::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('view');
});

it('has delete action on edit page header', function () {
    $record = Category::factory()->create();

    livewire(EditCategory::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete');
});

it('has edit action on view page header', function () {
    $record = Category::factory()->create();

    livewire(ViewCategory::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('edit');
});

it('can count table records', function () {
    Category::factory(3)->create();

    livewire(ListCategories::class)
        ->assertCountTableRecords(3);
});

// ===== Kitchen Display System (شاشة المطبخ) Fields =====
it('can render the kitchen station column', function () {
    Category::factory()->create();

    livewire(ListCategories::class)
        ->assertCanRenderTableColumn('kitchenStation.name');
});

it('can create a category with kitchen display fields', function () {
    $station = KitchenStation::factory()->create();

    livewire(CreateCategory::class)
        ->fillForm([
            'name' => 'فئة المطبخ',
            'kitchen_station_id' => $station->id,
            'show_on_kds' => true,
            'prep_minutes' => 8,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $record = Category::where('name', 'فئة المطبخ')->first();

    expect($record->kitchen_station_id)->toBe($station->id);
    expect($record->show_on_kds)->toBeTrue();
    expect($record->prep_minutes)->toBe(8);
});

it('can update a category kitchen display fields', function () {
    $station = KitchenStation::factory()->create();
    $record = Category::factory()->create(['kitchen_station_id' => $station->id]);

    livewire(EditCategory::class, ['record' => $record->getRouteKey()])
        ->fillForm([
            'kitchen_station_id' => null,
            'show_on_kds' => false,
            'prep_minutes' => 5,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $record->refresh();
    expect($record->kitchen_station_id)->toBeNull();
    expect($record->show_on_kds)->toBeFalse();
    expect($record->prep_minutes)->toBe(5);
});

it('leaves prep minutes empty to use the default time', function () {
    $record = Category::factory()->create();

    livewire(EditCategory::class, ['record' => $record->getRouteKey()])
        ->fillForm(['prep_minutes' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($record->refresh()->prep_minutes)->toBeNull();
});

it('shows the kitchen station name in the table', function () {
    $station = KitchenStation::factory()->create(['name' => 'شواية']);
    Category::factory()->create(['kitchen_station_id' => $station->id]);

    livewire(ListCategories::class)
        ->assertSee('شواية');
});
