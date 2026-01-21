<?php

use App\Enums\UserRole;
use App\Filament\Resources\ReturnPurchaseInvoices\Pages\CreateReturnPurchaseInvoice;
use App\Filament\Resources\ReturnPurchaseInvoices\Pages\EditReturnPurchaseInvoice;
use App\Filament\Resources\ReturnPurchaseInvoices\Pages\ListReturnPurchaseInvoices;
use App\Filament\Resources\ReturnPurchaseInvoices\Pages\ViewReturnPurchaseInvoice;
use App\Filament\Resources\ReturnPurchaseInvoices\RelationManagers\ItemsRelationManager;
use App\Models\Product;
use App\Models\ReturnPurchaseInvoice;
use App\Models\Supplier;
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
    livewire(ListReturnPurchaseInvoices::class)
        ->assertSuccessful();
});

it('can render the create page', function () {
    livewire(CreateReturnPurchaseInvoice::class)
        ->assertSuccessful();
});

it('can render the edit page', function () {
    $supplier = Supplier::factory()->create();
    $record = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
    ]);

    livewire(EditReturnPurchaseInvoice::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

it('can render the view page', function () {
    $supplier = Supplier::factory()->create();
    $record = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
    ]);

    livewire(ViewReturnPurchaseInvoice::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

// Table Column Tests
it('has column', function (string $column) {
    livewire(ListReturnPurchaseInvoices::class)
        ->assertTableColumnExists($column);
})->with(['id', 'supplier.name', 'user.name', 'items_count', 'total', 'notes', 'closed_at', 'created_at']);

it('can render column', function (string $column) {
    livewire(ListReturnPurchaseInvoices::class)
        ->assertCanRenderTableColumn($column);
})->with(['id', 'supplier.name', 'user.name', 'items_count', 'total', 'notes', 'closed_at', 'created_at']);

// Table Sorting Tests
it('can sort column', function (string $column) {
    $supplier = Supplier::factory()->create();
    $records = ReturnPurchaseInvoice::factory(5)->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
    ]);

    livewire(ListReturnPurchaseInvoices::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($column))
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($column));
})->with(['total', 'created_at']);

// Table Search Tests
it('can search return purchase invoices by supplier name', function () {
    $supplier1 = Supplier::factory()->create(['name' => 'مورد الأول']);
    $supplier2 = Supplier::factory()->create(['name' => 'مورد الثاني']);

    $invoice1 = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier1->id,
    ]);
    $invoice2 = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier2->id,
    ]);

    livewire(ListReturnPurchaseInvoices::class)
        ->searchTable('الأول')
        ->assertCanSeeTableRecords([$invoice1])
        ->assertCanNotSeeTableRecords([$invoice2]);
});

it('can search return purchase invoices by user name', function () {
    $user1 = User::factory()->create(['name' => 'أحمد محمد', 'role' => UserRole::ADMIN]);
    $user2 = User::factory()->create(['name' => 'محمود علي', 'role' => UserRole::ADMIN]);
    $supplier = Supplier::factory()->create();

    $invoice1 = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $user1->id,
        'supplier_id' => $supplier->id,
    ]);
    $invoice2 = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $user2->id,
        'supplier_id' => $supplier->id,
    ]);

    livewire(ListReturnPurchaseInvoices::class)
        ->searchTable('أحمد')
        ->assertCanSeeTableRecords([$invoice1])
        ->assertCanNotSeeTableRecords([$invoice2]);
});

it('can search return purchase invoices by id', function () {
    $supplier = Supplier::factory()->create();
    $invoice1 = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
    ]);
    $invoice2 = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
    ]);

    livewire(ListReturnPurchaseInvoices::class)
        ->searchTable((string) $invoice1->id)
        ->assertCanSeeTableRecords([$invoice1])
        ->assertCanNotSeeTableRecords([$invoice2]);
});

// CRUD Operations
it('can create a return purchase invoice', function () {
    $supplier = Supplier::factory()->create();
    $product = Product::factory()->create();

    $newRecord = ReturnPurchaseInvoice::factory()->make([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
    ]);

    livewire(CreateReturnPurchaseInvoice::class)
        ->fillForm([
            'user_id' => $this->admin->id,
            'supplier_id' => $supplier->id,
            'notes' => $newRecord->notes,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 5,
                    'price' => 100,
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(ReturnPurchaseInvoice::class, [
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
        'total' => 500, // 5 * 100
    ]);
});

it('can update a return purchase invoice', function () {
    $supplier = Supplier::factory()->create();
    $product = Product::factory()->create();

    $record = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
        'closed_at' => null,
    ]);

    $newSupplier = Supplier::factory()->create();

    livewire(EditReturnPurchaseInvoice::class, ['record' => $record->getRouteKey()])
        ->fillForm([
            'user_id' => $this->admin->id,
            'supplier_id' => $newSupplier->id,
            'notes' => 'ملاحظات محدثة',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 10,
                    'price' => 50,
                ],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(ReturnPurchaseInvoice::class, [
        'id' => $record->id,
        'supplier_id' => $newSupplier->id,
        'total' => 500, // 10 * 50
    ]);
});

it('can view a return purchase invoice', function () {
    $supplier = Supplier::factory()->create();
    $record = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
    ]);

    livewire(ViewReturnPurchaseInvoice::class, ['record' => $record->getRouteKey()])
        ->assertSchemaStateSet([
            'id' => $record->id,
            'supplier.name' => $supplier->name,
            'user.name' => $this->admin->name,
            'total' => $record->total,
        ]);
});

it('can delete a return purchase invoice', function () {
    $supplier = Supplier::factory()->create();
    $record = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
        'closed_at' => null,
    ]);

    livewire(EditReturnPurchaseInvoice::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete')
        ->callAction(DeleteAction::class);

    $this->assertModelMissing($record);
});

it('can bulk delete return purchase invoices', function () {
    $supplier = Supplier::factory()->create();
    $records = ReturnPurchaseInvoice::factory(5)->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
        'closed_at' => null,
    ]);

    livewire(ListReturnPurchaseInvoices::class)
        ->callTableBulkAction('delete', $records);

    foreach ($records as $record) {
        $this->assertModelMissing($record);
    }
});

// Form Validation Tests
it('can validate required user_id', function () {
    $supplier = Supplier::factory()->create();

    livewire(CreateReturnPurchaseInvoice::class)
        ->fillForm([
            'user_id' => null,
            'supplier_id' => $supplier->id,
        ])
        ->call('create')
        ->assertHasFormErrors(['user_id' => ['required']]);
});

it('can validate required supplier_id', function () {
    livewire(CreateReturnPurchaseInvoice::class)
        ->fillForm([
            'user_id' => $this->admin->id,
            'supplier_id' => null,
        ])
        ->call('create')
        ->assertHasFormErrors(['supplier_id' => ['required']]);
});

// Table Actions Tests
it('has view action on list page', function () {
    livewire(ListReturnPurchaseInvoices::class)
        ->assertTableActionExists('view');
});

it('has edit action on list page', function () {
    livewire(ListReturnPurchaseInvoices::class)
        ->assertTableActionExists('edit');
});

it('has delete action on list page', function () {
    livewire(ListReturnPurchaseInvoices::class)
        ->assertTableActionExists('delete');
});

it('has close action on list page', function () {
    livewire(ListReturnPurchaseInvoices::class)
        ->assertTableActionExists('close');
});

it('has print action on list page', function () {
    livewire(ListReturnPurchaseInvoices::class)
        ->assertTableActionExists('print');
});

// Page Actions Tests
it('has delete action on edit page header', function () {
    $supplier = Supplier::factory()->create();
    $record = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
        'closed_at' => null,
    ]);

    livewire(EditReturnPurchaseInvoice::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete');
});

it('has close action on edit page header', function () {
    $supplier = Supplier::factory()->create();
    $record = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
        'closed_at' => null,
    ]);

    livewire(EditReturnPurchaseInvoice::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('close');
});

// Record Visibility Tests
it('can see table records', function () {
    $supplier = Supplier::factory()->create();
    $records = ReturnPurchaseInvoice::factory(5)->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
    ]);

    livewire(ListReturnPurchaseInvoices::class)
        ->assertCanSeeTableRecords($records);
});

it('can count table records', function () {
    $supplier = Supplier::factory()->create();
    ReturnPurchaseInvoice::factory(3)->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
    ]);

    livewire(ListReturnPurchaseInvoices::class)
        ->assertCountTableRecords(3);
});

// Filter Tests
it('can filter by supplier', function () {
    $supplier1 = Supplier::factory()->create();
    $supplier2 = Supplier::factory()->create();

    $invoice1 = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier1->id,
    ]);
    $invoice2 = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier2->id,
    ]);

    livewire(ListReturnPurchaseInvoices::class)
        ->filterTable('supplier_id', $supplier1->id)
        ->assertCanSeeTableRecords([$invoice1])
        ->assertCanNotSeeTableRecords([$invoice2]);
});

it('can filter by user', function () {
    $user1 = User::factory()->create(['role' => UserRole::ADMIN]);
    $user2 = User::factory()->create(['role' => UserRole::ADMIN]);
    $supplier = Supplier::factory()->create();

    $invoice1 = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $user1->id,
        'supplier_id' => $supplier->id,
    ]);
    $invoice2 = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $user2->id,
        'supplier_id' => $supplier->id,
    ]);

    livewire(ListReturnPurchaseInvoices::class)
        ->filterTable('user_id', $user1->id)
        ->assertCanSeeTableRecords([$invoice1])
        ->assertCanNotSeeTableRecords([$invoice2]);
});

it('can filter by date range', function () {
    $supplier = Supplier::factory()->create();

    $oldInvoice = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
        'created_at' => now()->subDays(10),
    ]);

    $recentInvoice = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
        'created_at' => now()->subDays(2),
    ]);

    livewire(ListReturnPurchaseInvoices::class)
        ->filterTable('created_at', [
            'created_from' => now()->subDays(5)->toDateString(),
            'created_until' => now()->toDateString(),
        ])
        ->assertCanSeeTableRecords([$recentInvoice])
        ->assertCanNotSeeTableRecords([$oldInvoice]);
});

// Relationship Tests
it('shows supplier name correctly', function () {
    $supplier = Supplier::factory()->create(['name' => 'مورد الأول']);
    $invoice = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
    ]);

    livewire(ListReturnPurchaseInvoices::class)
        ->assertSee('مورد الأول');
});

it('shows user name correctly', function () {
    $supplier = Supplier::factory()->create();
    $invoice = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
    ]);

    livewire(ListReturnPurchaseInvoices::class)
        ->assertSee($this->admin->name);
});

// Default Sort Test
it('return purchase invoices are sorted by id descending by default', function () {
    $supplier = Supplier::factory()->create();

    $invoice1 = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
        'created_at' => now()->subDays(2),
    ]);

    $invoice2 = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
        'created_at' => now()->subDay(),
    ]);

    $invoice3 = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
        'created_at' => now(),
    ]);

    livewire(ListReturnPurchaseInvoices::class)
        ->assertCanSeeTableRecords([$invoice3, $invoice2, $invoice1], inOrder: true);
});

// Currency Formatting Test
it('displays total with EGP currency', function () {
    $supplier = Supplier::factory()->create();
    $invoice = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
        'total' => 150.50,
    ]);

    livewire(ListReturnPurchaseInvoices::class)
        ->assertSee('١٥٠'); // Arabic numerals are used in the UI
});

// Closed Status Tests
it('shows closed status badge correctly', function () {
    $supplier = Supplier::factory()->create();
    $closedInvoice = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
        'closed_at' => now(),
    ]);

    livewire(ListReturnPurchaseInvoices::class)
        ->assertTableColumnFormattedStateSet('closed_at', 'مغلق', record: $closedInvoice);
});

it('shows open status badge correctly', function () {
    $supplier = Supplier::factory()->create();
    $openInvoice = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
        'closed_at' => null,
    ]);

    livewire(ListReturnPurchaseInvoices::class)
        ->assertTableColumnFormattedStateSet('closed_at', 'مفتوح', record: $openInvoice);
});

it('cannot edit closed return purchase invoice', function () {
    $supplier = Supplier::factory()->create();
    $record = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
        'closed_at' => now(),
    ]);

    livewire(ListReturnPurchaseInvoices::class)
        ->assertTableActionHidden('edit', $record);
});

it('cannot delete closed return purchase invoice', function () {
    $supplier = Supplier::factory()->create();
    $record = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
        'closed_at' => now(),
    ]);

    livewire(ListReturnPurchaseInvoices::class)
        ->assertTableActionHidden('delete', $record);
});

// Relation Manager Tests
it('can see items relation manager on view page', function () {
    $supplier = Supplier::factory()->create();
    $record = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
    ]);

    livewire(ViewReturnPurchaseInvoice::class, ['record' => $record->getRouteKey()])
        ->assertSeeLivewire(ItemsRelationManager::class);
});

// Items Count Test
it('displays items count correctly', function () {
    $supplier = Supplier::factory()->create();
    $product = Product::factory()->create();

    $invoice = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
    ]);

    $invoice->items()->create([
        'product_id' => $product->id,
        'quantity' => 5,
        'price' => 100,
        'total' => 500,
    ]);

    $invoice->items()->create([
        'product_id' => $product->id,
        'quantity' => 3,
        'price' => 50,
        'total' => 150,
    ]);

    $invoice->refresh();

    livewire(ListReturnPurchaseInvoices::class)
        ->assertSee($invoice->items()->count());
});

// Total Calculation Tests
it('calculates total correctly when creating', function () {
    $supplier = Supplier::factory()->create();
    $product = Product::factory()->create();

    livewire(CreateReturnPurchaseInvoice::class)
        ->fillForm([
            'user_id' => $this->admin->id,
            'supplier_id' => $supplier->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 5,
                    'price' => 100,
                ],
                [
                    'product_id' => $product->id,
                    'quantity' => 3,
                    'price' => 50,
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(ReturnPurchaseInvoice::class, [
        'supplier_id' => $supplier->id,
        'total' => 650, // (5 * 100) + (3 * 50)
    ]);
});

it('calculates total correctly when updating', function () {
    $supplier = Supplier::factory()->create();
    $product = Product::factory()->create();

    $record = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
        'total' => 100,
        'closed_at' => null,
    ]);

    livewire(EditReturnPurchaseInvoice::class, ['record' => $record->getRouteKey()])
        ->fillForm([
            'user_id' => $this->admin->id,
            'supplier_id' => $supplier->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 10,
                    'price' => 75,
                ],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(ReturnPurchaseInvoice::class, [
        'id' => $record->id,
        'total' => 750, // 10 * 75
    ]);
});

// Notes Field Tests
it('can add notes to return purchase invoice', function () {
    $supplier = Supplier::factory()->create();
    $product = Product::factory()->create();

    $notes = 'هذه ملاحظات اختبارية';

    livewire(CreateReturnPurchaseInvoice::class)
        ->fillForm([
            'user_id' => $this->admin->id,
            'supplier_id' => $supplier->id,
            'notes' => $notes,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'price' => 100,
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(ReturnPurchaseInvoice::class, [
        'supplier_id' => $supplier->id,
        'notes' => $notes,
    ]);
});

it('shows truncated notes in table', function () {
    $supplier = Supplier::factory()->create();
    $longNotes = Str::random(100);

    $invoice = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $this->admin->id,
        'supplier_id' => $supplier->id,
        'notes' => $longNotes,
    ]);

    livewire(ListReturnPurchaseInvoices::class)
        ->assertSee(Str::limit($longNotes, 50));
});
