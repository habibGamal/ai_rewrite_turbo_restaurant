<?php

use App\Enums\OrderBehavior;
use App\Filament\Resources\OrderTypeRows\Pages\CreateOrderTypeRow;
use App\Filament\Resources\OrderTypeRows\Pages\EditOrderTypeRow;
use App\Filament\Resources\OrderTypeRows\Pages\ListOrderTypeRows;
use App\Models\OrderTypeRow;
use App\Models\Shift;
use App\Models\User;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
});

it('lists the seven system order types', function () {
    livewire(ListOrderTypeRows::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords(OrderTypeRow::query()->orderBy('id')->get(), inOrder: true);

    expect(OrderTypeRow::query()->where('is_system', true)->count())->toBe(7);
});

it('creates a custom counter order type', function () {
    livewire(CreateOrderTypeRow::class)
        ->fillForm([
            'code' => 'catering',
            'label' => 'كاترينج',
            'color' => 'primary',
            'icon' => 'heroicon-o-tag',
            'is_active' => true,
            'behavior' => OrderBehavior::COUNTER->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('order_types', [
        'code' => 'catering',
        'label' => 'كاترينج',
        'behavior' => OrderBehavior::COUNTER->value,
        'is_active' => true,
        'is_system' => false,
    ]);
});

it('rejects an invalid code', function () {
    livewire(CreateOrderTypeRow::class)
        ->fillForm([
            'code' => 'Catering',
            'label' => 'كاترينج',
            'color' => 'primary',
            'icon' => 'heroicon-o-tag',
            'is_active' => true,
            'behavior' => OrderBehavior::COUNTER->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['code']);

    $this->assertDatabaseMissing('order_types', ['code' => 'Catering']);
});

it('offers only the four cashier behaviors', function () {
    livewire(CreateOrderTypeRow::class)
        ->assertFormFieldExists(
            'behavior',
            fn (Select $field): bool => array_keys($field->getOptions()) === [
                OrderBehavior::DINE_IN->value,
                OrderBehavior::DELIVERY->value,
                OrderBehavior::COUNTER->value,
                OrderBehavior::COMPANIES->value,
            ],
        );
});

it('keeps the behavior of a system row', function () {
    $row = OrderTypeRow::query()->where('code', 'delivery')->firstOrFail();

    livewire(EditOrderTypeRow::class, ['record' => $row->getRouteKey()])
        ->fillForm(['behavior' => OrderBehavior::COUNTER->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($row->fresh()->behavior)->toBe(OrderBehavior::DELIVERY);
});

it('does not allow deleting a system row', function () {
    $row = OrderTypeRow::query()->where('code', 'dine_in')->firstOrFail();

    livewire(EditOrderTypeRow::class, ['record' => $row->getRouteKey()])
        ->assertActionDoesNotExist('delete');

    $user = User::factory()->viewer()->create();
    $user->givePermissionTo('delete_order_types');

    $custom = OrderTypeRow::query()->create([
        'code' => 'catering',
        'label' => 'كاترينج',
        'color' => 'primary',
        'icon' => 'heroicon-o-tag',
        'is_active' => true,
        'is_system' => false,
        'behavior' => OrderBehavior::COUNTER,
    ]);

    expect($user->can('delete', $row))->toBeFalse()
        ->and($user->can('delete', $custom))->toBeTrue();
});

it('refuses a behavior change while the type has open orders', function () {
    $row = OrderTypeRow::query()->create([
        'code' => 'lounge',
        'label' => 'لاونج',
        'color' => 'primary',
        'icon' => 'heroicon-o-tag',
        'is_active' => true,
        'is_system' => false,
        'behavior' => OrderBehavior::COUNTER,
    ]);

    $shift = Shift::factory()->create();

    DB::table('orders')->insert([
        'shift_id' => $shift->id,
        'status' => 'processing',
        'type' => 'lounge',
        'sub_total' => 0,
        'tax' => 0,
        'service' => 0,
        'discount' => 0,
        'temp_discount_percent' => 0,
        'total' => 0,
        'profit' => 0,
        'payment_status' => 'pending',
        'order_number' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    livewire(EditOrderTypeRow::class, ['record' => $row->getRouteKey()])
        ->fillForm(['behavior' => OrderBehavior::DINE_IN->value])
        ->call('save')
        ->assertHasFormErrors(['behavior']);

    expect($row->fresh()->behavior)->toBe(OrderBehavior::COUNTER);
});

it('allows a behavior change when the type has no open orders', function () {
    $row = OrderTypeRow::query()->create([
        'code' => 'lounge',
        'label' => 'لاونج',
        'color' => 'primary',
        'icon' => 'heroicon-o-tag',
        'is_active' => true,
        'is_system' => false,
        'behavior' => OrderBehavior::COUNTER,
    ]);

    livewire(EditOrderTypeRow::class, ['record' => $row->getRouteKey()])
        ->fillForm(['behavior' => OrderBehavior::DINE_IN->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($row->fresh()->behavior)->toBe(OrderBehavior::DINE_IN);
});
