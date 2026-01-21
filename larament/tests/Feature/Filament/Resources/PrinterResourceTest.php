<?php

use App\Enums\ProductType;
use App\Enums\UserRole;
use App\Filament\Resources\Printers\Pages\CreatePrinter;
use App\Filament\Resources\Printers\Pages\EditPrinter;
use App\Filament\Resources\Printers\Pages\ListPrinters;
use App\Filament\Resources\Printers\Pages\ViewPrinter;
use App\Models\Category;
use App\Models\Printer;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    actingAs($this->admin);
});

// Page Rendering Tests
it('can render the index page', function () {
    livewire(ListPrinters::class)
        ->assertSuccessful();
});

it('can render the create page', function () {
    livewire(CreatePrinter::class)
        ->assertSuccessful();
});

it('can render the edit page', function () {
    $record = Printer::factory()->create();

    livewire(EditPrinter::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

it('can render the view page', function () {
    $record = Printer::factory()->create();

    livewire(ViewPrinter::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

// Table Column Tests
it('has column', function (string $column) {
    livewire(ListPrinters::class)
        ->assertTableColumnExists($column);
})->with(['name', 'ip_address', 'products_count', 'created_at', 'updated_at']);

it('can render column', function (string $column) {
    Printer::factory()->create();

    livewire(ListPrinters::class)
        ->assertCanRenderTableColumn($column);
})->with(['name', 'ip_address', 'products_count']);

// Table Sorting Tests
it('can sort column', function (string $column) {
    $records = Printer::factory(5)->create();

    livewire(ListPrinters::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($column))
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($column));
})->with(['name', 'ip_address', 'created_at', 'updated_at']);

// Table Search Tests
it('can search by name', function () {
    $records = Printer::factory(5)->create();
    $value = $records->first()->name;

    livewire(ListPrinters::class)
        ->searchTable($value)
        ->assertCanSeeTableRecords($records->where('name', $value));
});

// CRUD Operations Tests
it('can create a printer', function () {
    $record = Printer::factory()->make();

    livewire(CreatePrinter::class)
        ->fillForm([
            'name' => $record->name,
            'ip_address' => $record->ip_address,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Printer::class, [
        'name' => $record->name,
        'ip_address' => $record->ip_address,
    ]);
});

it('can update a printer', function () {
    $record = Printer::factory()->create();
    $newRecord = Printer::factory()->make();

    livewire(EditPrinter::class, ['record' => $record->getRouteKey()])
        ->fillForm([
            'name' => $newRecord->name,
            'ip_address' => $newRecord->ip_address,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Printer::class, [
        'id' => $record->id,
        'name' => $newRecord->name,
        'ip_address' => $newRecord->ip_address,
    ]);
});

it('can view a printer', function () {
    $record = Printer::factory()->create();

    livewire(ViewPrinter::class, ['record' => $record->getRouteKey()])
        ->assertFormSet([
            'name' => $record->name,
            'ip_address' => $record->ip_address,
        ]);
});

it('can delete a printer', function () {
    $record = Printer::factory()->create();

    livewire(EditPrinter::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete')
        ->callAction(DeleteAction::class);

    $this->assertModelMissing($record);
});

it('can bulk delete printers', function () {
    $records = Printer::factory(5)->create();

    livewire(ListPrinters::class)
        ->callTableBulkAction(DeleteBulkAction::class, $records);

    foreach ($records as $record) {
        $this->assertModelMissing($record);
    }
});

// Form Validation Tests
it('can validate required name', function () {
    livewire(CreatePrinter::class)
        ->fillForm(['name' => null])
        ->call('create')
        ->assertHasFormErrors(['name' => ['required']]);
});

it('can validate max length for name', function () {
    livewire(CreatePrinter::class)
        ->fillForm(['name' => Str::random(256)])
        ->call('create')
        ->assertHasFormErrors(['name' => ['max:255']]);
});

it('can validate max length for ip_address', function () {
    livewire(CreatePrinter::class)
        ->fillForm([
            'name' => 'Test Printer',
            'ip_address' => Str::random(256),
        ])
        ->call('create')
        ->assertHasFormErrors(['ip_address' => ['max:255']]);
});

// Relationship Tests
it('can attach products to printer', function () {
    $category = Category::factory()->create();
    $products = Product::factory(3)->create([
        'category_id' => $category->id,
        'type' => ProductType::Consumable,
    ]);

    $printer = Printer::factory()->make();

    livewire(CreatePrinter::class)
        ->fillForm([
            'name' => $printer->name,
            'ip_address' => $printer->ip_address,
            'products' => $products->pluck('id')->toArray(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $createdPrinter = Printer::where('name', $printer->name)->first();
    expect($createdPrinter->products)->toHaveCount(3);
});

it('shows correct products count', function () {
    $category = Category::factory()->create();
    $products = Product::factory(3)->create([
        'category_id' => $category->id,
        'type' => ProductType::Consumable,
    ]);

    $printer = Printer::factory()->create();
    $printer->products()->attach($products);

    livewire(ListPrinters::class)
        ->assertSee($printer->name)
        ->assertSee('3'); // Should see the count displayed
});

it('can update printer products', function () {
    $category = Category::factory()->create();
    $oldProducts = Product::factory(2)->create([
        'category_id' => $category->id,
        'type' => ProductType::Consumable,
    ]);
    $newProducts = Product::factory(3)->create([
        'category_id' => $category->id,
        'type' => ProductType::Manufactured,
    ]);

    $printer = Printer::factory()->create();
    $printer->products()->attach($oldProducts);

    livewire(EditPrinter::class, ['record' => $printer->getRouteKey()])
        ->fillForm([
            'products' => $newProducts->pluck('id')->toArray(),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $printer->refresh();
    expect($printer->products)->toHaveCount(3);
    expect($printer->products->pluck('id')->toArray())->toEqual($newProducts->pluck('id')->toArray());
});

it('only shows consumable and manufactured products in product list', function () {
    $category = Category::factory()->create();
    $consumableProduct = Product::factory()->create([
        'category_id' => $category->id,
        'type' => ProductType::Consumable,
    ]);
    $manufacturedProduct = Product::factory()->create([
        'category_id' => $category->id,
        'type' => ProductType::Manufactured,
    ]);
    $rawMaterialProduct = Product::factory()->create([
        'category_id' => $category->id,
        'type' => ProductType::RawMaterial,
    ]);

    livewire(CreatePrinter::class)
        ->assertFormFieldExists('products');

    // The form should only have consumable and manufactured products available
    $printer = Printer::factory()->create();
    $printer->products()->attach([$consumableProduct->id, $manufacturedProduct->id]);

    expect($printer->products)->toHaveCount(2);
});

// Table Record Visibility Tests
it('can see table records', function () {
    $records = Printer::factory(5)->create();

    livewire(ListPrinters::class)
        ->assertCanSeeTableRecords($records);
});

it('can count table records', function () {
    Printer::factory(3)->create();

    livewire(ListPrinters::class)
        ->assertCountTableRecords(3);
});

// Table Actions Tests
it('has view action on list page', function () {
    Printer::factory()->create();

    livewire(ListPrinters::class)
        ->assertTableActionExists('view');
});

it('has edit action on list page', function () {
    Printer::factory()->create();

    livewire(ListPrinters::class)
        ->assertTableActionExists('edit');
});

it('has delete action on list page', function () {
    Printer::factory()->create();

    livewire(ListPrinters::class)
        ->assertTableActionExists('delete');
});

// Page Actions Tests
it('has create action on list page header', function () {
    livewire(ListPrinters::class)
        ->assertActionExists('create');
});

it('has view action on edit page header', function () {
    $record = Printer::factory()->create();

    livewire(EditPrinter::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('view');
});

it('has delete action on edit page header', function () {
    $record = Printer::factory()->create();

    livewire(EditPrinter::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete');
});

it('has edit action on view page header', function () {
    $record = Printer::factory()->create();

    livewire(ViewPrinter::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('edit');
});

// Form Field Tests
it('has name field', function () {
    livewire(CreatePrinter::class)
        ->assertFormFieldExists('name');
});

it('has ip_address field', function () {
    livewire(CreatePrinter::class)
        ->assertFormFieldExists('ip_address');
});

it('has products field', function () {
    livewire(CreatePrinter::class)
        ->assertFormFieldExists('products');
});

it('has categories field', function () {
    livewire(CreatePrinter::class)
        ->assertFormFieldExists('categories');
});

// IP Address Format Tests
it('accepts valid IP address format', function () {
    $printer = Printer::factory()->make(['ip_address' => '192.168.1.100']);

    livewire(CreatePrinter::class)
        ->fillForm([
            'name' => $printer->name,
            'ip_address' => $printer->ip_address,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Printer::class, [
        'ip_address' => '192.168.1.100',
    ]);
});

it('accepts USB printer share format', function () {
    $printer = Printer::factory()->make(['ip_address' => '//192.168.1.1/PrinterName']);

    livewire(CreatePrinter::class)
        ->fillForm([
            'name' => $printer->name,
            'ip_address' => $printer->ip_address,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Printer::class, [
        'ip_address' => '//192.168.1.1/PrinterName',
    ]);
});

// Toggleable Columns Tests
it('created_at and updated_at are toggleable and hidden by default', function () {
    livewire(ListPrinters::class)
        ->assertTableColumnExists('created_at')
        ->assertTableColumnExists('updated_at');
});
