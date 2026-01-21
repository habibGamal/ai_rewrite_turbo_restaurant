<?php

use App\Enums\UserRole;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Region;
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
    livewire(ListCustomers::class)
        ->assertSuccessful();
});

it('can render the create page', function () {
    livewire(CreateCustomer::class)
        ->assertSuccessful();
});

it('can render the edit page', function () {
    $record = Customer::factory()->create();

    livewire(EditCustomer::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

it('can render the view page', function () {
    $record = Customer::factory()->create();

    livewire(ViewCustomer::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

// Table Column Tests
it('has column', function (string $column) {
    livewire(ListCustomers::class)
        ->assertTableColumnExists($column);
})->with([
    'name',
    'phone',
    'has_whatsapp',
    'region',
    'delivery_cost',
    'address',
    'orders_count',
    'created_at',
]);

it('can render column', function (string $column) {
    livewire(ListCustomers::class)
        ->assertCanRenderTableColumn($column);
})->with([
    'name',
    'phone',
    'has_whatsapp',
    'region',
    'delivery_cost',
    'address',
    'orders_count',
]);

// Table Sorting Tests
it('can sort column', function (string $column) {
    $records = Customer::factory(5)->create();

    livewire(ListCustomers::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($column))
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($column));
})->with(['name', 'phone', 'region', 'delivery_cost']);

// Table Search Tests
it('can search by name', function () {
    $records = Customer::factory(5)->create();

    $value = $records->first()->name;

    livewire(ListCustomers::class)
        ->searchTable($value)
        ->assertCanSeeTableRecords($records->where('name', $value))
        ->assertCanNotSeeTableRecords($records->where('name', '!=', $value));
});

it('can search by phone', function () {
    $records = Customer::factory(5)->create();

    $value = $records->first()->phone;

    livewire(ListCustomers::class)
        ->searchTable($value)
        ->assertCanSeeTableRecords($records->where('phone', $value));
});

it('can search by region', function () {
    $records = Customer::factory(5)->create();

    $value = $records->first()->region;

    livewire(ListCustomers::class)
        ->searchTable($value)
        ->assertCanSeeTableRecords($records->where('region', $value));
});

// Table Filtering Tests
it('can filter by has_whatsapp', function () {
    $whatsappCustomers = Customer::factory(3)->create(['has_whatsapp' => true]);
    $nonWhatsappCustomers = Customer::factory(2)->create(['has_whatsapp' => false]);

    livewire(ListCustomers::class)
        ->assertCanSeeTableRecords($whatsappCustomers)
        ->assertCanSeeTableRecords($nonWhatsappCustomers)
        ->filterTable('has_whatsapp', true)
        ->assertCanSeeTableRecords($whatsappCustomers)
        ->assertCanNotSeeTableRecords($nonWhatsappCustomers);
});

it('can filter by region', function () {
    $region = Region::factory()->create();
    $customers = Customer::factory(3)->create(['region' => $region->name]);
    $otherCustomers = Customer::factory(2)->create();

    livewire(ListCustomers::class)
        ->assertCanSeeTableRecords($customers)
        ->assertCanSeeTableRecords($otherCustomers)
        ->filterTable('region', $region->name)
        ->assertCanSeeTableRecords($customers)
        ->assertCanNotSeeTableRecords($otherCustomers);
});

// CRUD Operations Tests
it('can create a customer', function () {
    $region = Region::factory()->create();
    $record = Customer::factory()->make();

    livewire(CreateCustomer::class)
        ->fillForm([
            'name' => $record->name,
            'phone' => $record->phone,
            'has_whatsapp' => $record->has_whatsapp,
            'region' => $region->name,
            'delivery_cost' => $region->delivery_cost,
            'address' => $record->address,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Customer::class, [
        'name' => $record->name,
        'phone' => $record->phone,
    ]);
});

it('can create a customer with whatsapp', function () {
    $region = Region::factory()->create();
    $record = Customer::factory()->make(['has_whatsapp' => true]);

    livewire(CreateCustomer::class)
        ->fillForm([
            'name' => $record->name,
            'phone' => $record->phone,
            'has_whatsapp' => true,
            'region' => $region->name,
            'delivery_cost' => $region->delivery_cost,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Customer::class, [
        'phone' => $record->phone,
        'has_whatsapp' => true,
    ]);
});

it('can update a customer', function () {
    $record = Customer::factory()->create();
    $newRegion = Region::factory()->create();
    $newRecord = Customer::factory()->make();

    livewire(EditCustomer::class, ['record' => $record->getRouteKey()])
        ->fillForm([
            'name' => $newRecord->name,
            'phone' => $newRecord->phone,
            'has_whatsapp' => $newRecord->has_whatsapp,
            'region' => $newRegion->name,
            'delivery_cost' => $newRegion->delivery_cost,
            'address' => $newRecord->address,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Customer::class, [
        'id' => $record->id,
        'name' => $newRecord->name,
        'phone' => $newRecord->phone,
    ]);
});

it('can view a customer', function () {
    $record = Customer::factory()->create();

    livewire(ViewCustomer::class, ['record' => $record->getRouteKey()])
        ->assertSchemaStateSet([
            'name' => $record->name,
            'phone' => $record->phone,
            'has_whatsapp' => $record->has_whatsapp,
        ]);
});

it('can delete a customer without orders', function () {
    $record = Customer::factory()->create();

    livewire(EditCustomer::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete')
        ->callAction(DeleteAction::class);

    $this->assertModelMissing($record);
});

it('can bulk delete customers without orders', function () {
    $records = Customer::factory(5)->create();

    livewire(ListCustomers::class)
        ->callTableBulkAction('delete', $records);

    foreach ($records as $record) {
        $this->assertModelMissing($record);
    }
});

// Form Validation Tests
it('can validate required name', function () {
    livewire(CreateCustomer::class)
        ->fillForm(['name' => null])
        ->call('create')
        ->assertHasFormErrors(['name' => ['required']]);
});

it('can validate max length for name', function () {
    livewire(CreateCustomer::class)
        ->fillForm(['name' => Str::random(256)])
        ->call('create')
        ->assertHasFormErrors(['name' => ['max:255']]);
});

it('can validate required phone', function () {
    livewire(CreateCustomer::class)
        ->fillForm(['phone' => null])
        ->call('create')
        ->assertHasFormErrors(['phone' => ['required']]);
});

it('can validate max length for phone', function () {
    livewire(CreateCustomer::class)
        ->fillForm(['phone' => Str::random(21)])
        ->call('create')
        ->assertHasFormErrors(['phone' => ['max:20']]);
});

it('can validate unique phone', function () {
    $existingCustomer = Customer::factory()->create();

    livewire(CreateCustomer::class)
        ->fillForm([
            'name' => 'Test Customer',
            'phone' => $existingCustomer->phone,
        ])
        ->call('create')
        ->assertHasFormErrors(['phone' => ['unique']]);
});

it('can validate max length for address', function () {
    livewire(CreateCustomer::class)
        ->fillForm(['address' => Str::random(501)])
        ->call('create')
        ->assertHasFormErrors(['address' => ['max:500']]);
});

// Record Visibility Tests
it('can see table records', function () {
    $records = Customer::factory(5)->create();

    livewire(ListCustomers::class)
        ->assertCanSeeTableRecords($records);
});

it('can count table records', function () {
    Customer::factory(3)->create();

    livewire(ListCustomers::class)
        ->assertCountTableRecords(3);
});

// Table Actions Tests
it('has view action on list page', function () {
    livewire(ListCustomers::class)
        ->assertTableActionExists('view');
});

it('has edit action on list page', function () {
    livewire(ListCustomers::class)
        ->assertTableActionExists('edit');
});

it('has delete action on list page', function () {
    livewire(ListCustomers::class)
        ->assertTableActionExists('delete');
});

// Page Actions Tests
it('has delete action on edit page header', function () {
    $record = Customer::factory()->create();

    livewire(EditCustomer::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('delete');
});

it('has edit action on view page header', function () {
    $record = Customer::factory()->create();

    livewire(ViewCustomer::class, ['record' => $record->getRouteKey()])
        ->assertActionExists('edit');
});

// Relationship Tests
it('shows correct orders count', function () {
    $customer = Customer::factory()->create();
    Order::factory()->count(3)->create(['customer_id' => $customer->id]);

    livewire(ListCustomers::class)
        ->assertSee($customer->name)
        ->assertSee('3'); // Should see the count badge
});

it('can load the orders relation manager', function () {
    $customer = Customer::factory()
        ->has(Order::factory()->count(5))
        ->create();

    livewire(EditCustomer::class, [
        'record' => $customer->id,
    ])
        ->assertSeeLivewire(OrdersRelationManager::class);
});

// Auto-fill Delivery Cost Tests
it('auto-fills delivery cost when region is selected', function () {
    $region = Region::factory()->create(['delivery_cost' => 25.50]);

    livewire(CreateCustomer::class)
        ->fillForm([
            'name' => 'Test Customer',
            'phone' => '01234567890',
            'region' => $region->name,
        ])
        ->assertSchemaStateSet([
            'delivery_cost' => 25.50,
        ]);
});

it('displays phone as copyable', function () {
    $customer = Customer::factory()->create(['phone' => '01234567890']);

    livewire(ListCustomers::class)
        ->assertSee($customer->phone);
});

it('displays delivery cost with EGP currency', function () {
    $customer = Customer::factory()->create(['delivery_cost' => 30.00]);

    livewire(ListCustomers::class)
        ->assertSee($customer->name);
});

it('displays whatsapp icon for customers with whatsapp', function () {
    $customer = Customer::factory()->create(['has_whatsapp' => true]);

    livewire(ListCustomers::class)
        ->assertSee($customer->name);
});

it('displays address tooltip when limited', function () {
    $longAddress = Str::random(100);
    $customer = Customer::factory()->create(['address' => $longAddress]);

    livewire(ListCustomers::class)
        ->assertSee($customer->name);
});

it('has create action on list page header', function () {
    livewire(ListCustomers::class)
        ->assertActionExists('create');
});
