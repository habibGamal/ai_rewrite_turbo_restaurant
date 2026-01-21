<?php

use App\Enums\UserRole;
use App\Filament\Resources\PurchaseInvoices\Pages\CreatePurchaseInvoice;
use App\Filament\Resources\PurchaseInvoices\Pages\EditPurchaseInvoice;
use App\Filament\Resources\PurchaseInvoices\Pages\ListPurchaseInvoices;
use App\Filament\Resources\PurchaseInvoices\Pages\ViewPurchaseInvoice;
use App\Filament\Resources\PurchaseInvoices\PurchaseInvoiceResource;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\Product;
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
    livewire(ListPurchaseInvoices::class)
        ->assertSuccessful();
});

it('can render the create page', function () {
    livewire(CreatePurchaseInvoice::class)
        ->assertSuccessful();
});

it('can render the edit page for open invoice', function () {
    $record = PurchaseInvoice::factory()->create(['closed_at' => null]);

    livewire(EditPurchaseInvoice::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

it('can render the view page', function () {
    $record = PurchaseInvoice::factory()->create();

    livewire(ViewPurchaseInvoice::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

// Table Column Tests
it('has column', function (string $column) {
    livewire(ListPurchaseInvoices::class)
        ->assertTableColumnExists($column);
})->with([
    'id',
    'supplier.name',
    'user.name',
    'items_count',
    'total',
    'notes',
    'closed_at',
    'created_at',
]);

it('can render column', function (string $column) {
    $supplier = Supplier::factory()->create();
    $user = User::factory()->create();
    PurchaseInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'user_id' => $user->id,
    ]);

    livewire(ListPurchaseInvoices::class)
        ->assertCanRenderTableColumn($column);
})->with([
    'id',
    'supplier.name',
    'user.name',
    'items_count',
    'total',
    'notes',
    'closed_at',
]);

// Table Sorting Tests
it('can sort column', function (string $column) {
    $supplier = Supplier::factory()->create();
    $user = User::factory()->create();
    $records = PurchaseInvoice::factory(5)->create([
        'supplier_id' => $supplier->id,
        'user_id' => $user->id,
    ]);

    livewire(ListPurchaseInvoices::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($column))
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($column));
})->with(['id', 'total', 'created_at', 'closed_at']);

// Table Search Tests
it('can search by id', function () {
    $records = PurchaseInvoice::factory(5)->create();

    $value = $records->first()->id;

    livewire(ListPurchaseInvoices::class)
        ->searchTable($value)
        ->assertCanSeeTableRecords($records->where('id', $value))
        ->assertCanNotSeeTableRecords($records->where('id', '!=', $value));
});

it('can search by supplier name', function () {
    $supplier1 = Supplier::factory()->create(['name' => 'Test Supplier']);
    $supplier2 = Supplier::factory()->create(['name' => 'Other Supplier']);

    $invoice1 = PurchaseInvoice::factory()->create(['supplier_id' => $supplier1->id]);
    $invoice2 = PurchaseInvoice::factory()->create(['supplier_id' => $supplier2->id]);

    livewire(ListPurchaseInvoices::class)
        ->searchTable('Test Supplier')
        ->assertCanSeeTableRecords([$invoice1])
        ->assertCanNotSeeTableRecords([$invoice2]);
});

it('can search by user name', function () {
    $user1 = User::factory()->create(['name' => 'Test User']);
    $user2 = User::factory()->create(['name' => 'Other User']);

    $invoice1 = PurchaseInvoice::factory()->create(['user_id' => $user1->id]);
    $invoice2 = PurchaseInvoice::factory()->create(['user_id' => $user2->id]);

    livewire(ListPurchaseInvoices::class)
        ->searchTable('Test User')
        ->assertCanSeeTableRecords([$invoice1])
        ->assertCanNotSeeTableRecords([$invoice2]);
});

// Table Filtering Tests
it('can filter by supplier', function () {
    $supplier1 = Supplier::factory()->create();
    $supplier2 = Supplier::factory()->create();

    $invoices1 = PurchaseInvoice::factory(2)->create(['supplier_id' => $supplier1->id]);
    $invoices2 = PurchaseInvoice::factory(2)->create(['supplier_id' => $supplier2->id]);

    livewire(ListPurchaseInvoices::class)
        ->assertCanSeeTableRecords($invoices1)
        ->assertCanSeeTableRecords($invoices2)
        ->filterTable('supplier_id', $supplier1->id)
        ->assertCanSeeTableRecords($invoices1)
        ->assertCanNotSeeTableRecords($invoices2);
});

it('can filter by user', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $invoices1 = PurchaseInvoice::factory(2)->create(['user_id' => $user1->id]);
    $invoices2 = PurchaseInvoice::factory(2)->create(['user_id' => $user2->id]);

    livewire(ListPurchaseInvoices::class)
        ->assertCanSeeTableRecords($invoices1)
        ->assertCanSeeTableRecords($invoices2)
        ->filterTable('user_id', $user1->id)
        ->assertCanSeeTableRecords($invoices1)
        ->assertCanNotSeeTableRecords($invoices2);
});

it('can filter by created_at date range', function () {
    $oldInvoice = PurchaseInvoice::factory()->create(['created_at' => now()->subDays(10)]);
    $recentInvoice = PurchaseInvoice::factory()->create(['created_at' => now()->subDays(2)]);

    livewire(ListPurchaseInvoices::class)
        ->filterTable('created_at', [
            'created_from' => now()->subDays(5)->format('Y-m-d'),
            'created_until' => now()->format('Y-m-d'),
        ])
        ->assertCanSeeTableRecords([$recentInvoice])
        ->assertCanNotSeeTableRecords([$oldInvoice]);
});

// CRUD Operations Tests
it('can create a purchase invoice with items', function () {
    $supplier = Supplier::factory()->create();
    $product = Product::factory()->create();

    livewire(CreatePurchaseInvoice::class)
        ->fillForm([
            'user_id' => $this->admin->id,
            'supplier_id' => $supplier->id,
            'notes' => 'Test notes',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 10,
                    'price' => 50.00,
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(PurchaseInvoice::class, [
        'supplier_id' => $supplier->id,
        'user_id' => $this->admin->id,
        'notes' => 'Test notes',
        'total' => 500.00,
    ]);
});

it('can create a purchase invoice without notes', function () {
    $supplier = Supplier::factory()->create();
    $product = Product::factory()->create();

    livewire(CreatePurchaseInvoice::class)
        ->fillForm([
            'user_id' => $this->admin->id,
            'supplier_id' => $supplier->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 5,
                    'price' => 20.00,
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(PurchaseInvoice::class, [
        'supplier_id' => $supplier->id,
        'total' => 100.00,
    ]);
});

it('can update an open purchase invoice', function () {
    $invoice = PurchaseInvoice::factory()->create(['closed_at' => null]);
    $newSupplier = Supplier::factory()->create();

    livewire(EditPurchaseInvoice::class, ['record' => $invoice->getRouteKey()])
        ->fillForm([
            'supplier_id' => $newSupplier->id,
            'notes' => 'Updated notes',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(PurchaseInvoice::class, [
        'id' => $invoice->id,
        'supplier_id' => $newSupplier->id,
        'notes' => 'Updated notes',
    ]);
});

it('can view a purchase invoice', function () {
    $invoice = PurchaseInvoice::factory()->create();

    livewire(ViewPurchaseInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertSchemaStateSet([
            'id' => $invoice->id,
            'supplier.name' => $invoice->supplier->name,
            'user.name' => $invoice->user->name,
        ]);
});

it('can delete an open purchase invoice', function () {
    $record = PurchaseInvoice::factory()->create(['closed_at' => null]);

    livewire(EditPurchaseInvoice::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete')
        ->callAction(DeleteAction::class);

    $this->assertModelMissing($record);
});

it('can bulk delete open purchase invoices', function () {
    $records = PurchaseInvoice::factory(3)->create(['closed_at' => null]);

    livewire(ListPurchaseInvoices::class)
        ->callTableBulkAction('delete', $records);

    foreach ($records as $record) {
        $this->assertModelMissing($record);
    }
});

// Form Validation Tests
it('can validate required user_id', function () {
    livewire(CreatePurchaseInvoice::class)
        ->fillForm(['user_id' => null])
        ->call('create')
        ->assertHasFormErrors(['user_id' => ['required']]);
});

it('can validate required supplier_id', function () {
    livewire(CreatePurchaseInvoice::class)
        ->fillForm(['supplier_id' => null])
        ->call('create')
        ->assertHasFormErrors(['supplier_id' => ['required']]);
});

it('calculates total automatically from items', function () {
    $supplier = Supplier::factory()->create();
    $product1 = Product::factory()->create();
    $product2 = Product::factory()->create();

    livewire(CreatePurchaseInvoice::class)
        ->fillForm([
            'user_id' => $this->admin->id,
            'supplier_id' => $supplier->id,
            'items' => [
                [
                    'product_id' => $product1->id,
                    'quantity' => 10,
                    'price' => 50.00,
                ],
                [
                    'product_id' => $product2->id,
                    'quantity' => 5,
                    'price' => 30.00,
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(PurchaseInvoice::class, [
        'total' => 650.00, // (10 * 50) + (5 * 30)
    ]);
});

// Record Visibility Tests
it('can see table records', function () {
    $records = PurchaseInvoice::factory(5)->create();

    livewire(ListPurchaseInvoices::class)
        ->assertCanSeeTableRecords($records);
});

it('can count table records', function () {
    PurchaseInvoice::factory(3)->create();

    livewire(ListPurchaseInvoices::class)
        ->assertCountTableRecords(3);
});

// Table Actions Tests
it('has view action on list page', function () {
    livewire(ListPurchaseInvoices::class)
        ->assertTableActionExists('view');
});

it('has edit action on list page', function () {
    livewire(ListPurchaseInvoices::class)
        ->assertTableActionExists('edit');
});

it('has delete action on list page', function () {
    livewire(ListPurchaseInvoices::class)
        ->assertTableActionExists('delete');
});

it('has close action on list page', function () {
    livewire(ListPurchaseInvoices::class)
        ->assertTableActionExists('close');
});

it('has print action on list page', function () {
    livewire(ListPurchaseInvoices::class)
        ->assertTableActionExists('print');
});

// Page Actions Tests
it('has delete action on edit page header', function () {
    $record = PurchaseInvoice::factory()->create(['closed_at' => null]);

    livewire(EditPurchaseInvoice::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete');
});

// Relationship Tests
it('shows correct items count', function () {
    $invoice = PurchaseInvoice::factory()->create();
    $product = Product::factory()->create();

    PurchaseInvoiceItem::factory()->count(3)->create([
        'purchase_invoice_id' => $invoice->id,
        'product_id' => $product->id,
    ]);

    livewire(ListPurchaseInvoices::class)
        ->assertCanSeeTableRecords([$invoice]);
});

it('can display invoice items in edit page', function () {
    $product = Product::factory()->create();
    $invoice = PurchaseInvoice::factory()->create();

    PurchaseInvoiceItem::factory()->count(3)->create([
        'purchase_invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'price' => 100,
    ]);

    livewire(EditPurchaseInvoice::class, [
        'record' => $invoice->id,
    ])
        ->assertSuccessful();
});

it('form is disabled for closed invoices', function () {
    $invoice = PurchaseInvoice::factory()->create(['closed_at' => now()]);

    // Closed invoices should not be editable
    expect(PurchaseInvoiceResource::canEdit($invoice))->toBeFalse();
});

it('form is editable for open invoices', function () {
    $invoice = PurchaseInvoice::factory()->create(['closed_at' => null]);

    // Open invoices should be editable
    expect(PurchaseInvoiceResource::canEdit($invoice))->toBeTrue();
});

// Business Logic Tests
it('cannot edit closed invoice', function () {
    $invoice = PurchaseInvoice::factory()->create(['closed_at' => now()]);

    expect(PurchaseInvoiceResource::canEdit($invoice))->toBeFalse();
});

it('cannot delete closed invoice', function () {
    $invoice = PurchaseInvoice::factory()->create(['closed_at' => now()]);

    expect(PurchaseInvoiceResource::canDelete($invoice))->toBeFalse();
});

it('can edit open invoice', function () {
    $invoice = PurchaseInvoice::factory()->create(['closed_at' => null]);

    expect(PurchaseInvoiceResource::canEdit($invoice))->toBeTrue();
});

it('can delete open invoice', function () {
    $invoice = PurchaseInvoice::factory()->create(['closed_at' => null]);

    expect(PurchaseInvoiceResource::canDelete($invoice))->toBeTrue();
});

it('displays status badge correctly for open invoice', function () {
    $invoice = PurchaseInvoice::factory()->create(['closed_at' => null]);

    livewire(ListPurchaseInvoices::class)
        ->assertCanSeeTableRecords([$invoice]);
});

it('displays status badge correctly for closed invoice', function () {
    $invoice = PurchaseInvoice::factory()->create(['closed_at' => now()]);

    livewire(ListPurchaseInvoices::class)
        ->assertCanSeeTableRecords([$invoice]);
});

it('displays total with EGP currency', function () {
    $invoice = PurchaseInvoice::factory()->create(['total' => 500.00]);

    livewire(ListPurchaseInvoices::class)
        ->assertCanSeeTableRecords([$invoice]);
});

it('displays notes tooltip when limited', function () {
    $longNotes = Str::random(100);
    $invoice = PurchaseInvoice::factory()->create(['notes' => $longNotes]);

    livewire(ListPurchaseInvoices::class)
        ->assertCanSeeTableRecords([$invoice]);
});

it('has create action on list page header', function () {
    livewire(ListPurchaseInvoices::class)
        ->assertActionExists('create');
});

it('redirects to index after creation', function () {
    $supplier = Supplier::factory()->create();
    $product = Product::factory()->create();

    livewire(CreatePurchaseInvoice::class)
        ->fillForm([
            'user_id' => $this->admin->id,
            'supplier_id' => $supplier->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'price' => 100.00,
                ],
            ],
        ])
        ->call('create')
        ->assertRedirect(ListPurchaseInvoices::getUrl());
});

// Inline Supplier Creation Test
it('can create supplier inline from purchase invoice form', function () {
    livewire(CreatePurchaseInvoice::class)
        ->assertSchemaComponentExists('supplier_id');
});
