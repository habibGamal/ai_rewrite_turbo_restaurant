<?php

use App\DTOs\Orders\CreateOrderDTO;
use App\Enums\OrderBehavior;
use App\Events\Orders\OrderTypeChangedBroadcast;
use App\Filament\Resources\OrderTypeRows\OrderTypeRowResource;
use App\Models\DineTable;
use App\Models\OrderTypeRow;
use App\Models\Shift;
use App\Models\User;
use App\Modules\Restaurant\Behaviors\DineInBehavior;
use App\Orders\OrderOpening;
use App\Modules\Restaurant\Services\FloorLayoutService;
use App\Services\Orders\OrderService;
use App\Modules\Restaurant\Services\TableManagementService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

it('keeps unknown layout keys and rejects duplicate tables', function () {
    Storage::fake('local');
    OrderTypeRow::query()->create([
        'code' => 'floor_test',
        'label' => 'Floor',
        'color' => 'primary',
        'icon' => 'heroicon-o-tag',
        'behavior' => OrderBehavior::DINE_IN,
        'is_active' => true,
        'is_system' => false,
    ]);
    $service = app(FloorLayoutService::class);
    $table = [
        'number' => '4',
        'capacity' => 4,
        'zone' => '',
        'active' => true,
        'col' => 0,
        'row' => 0,
        'colSpan' => 1,
        'rowSpan' => 1,
    ];
    $layout = [
        'version' => 1,
        'enabled' => true,
        'columns' => 2,
        'rows' => 2,
        'sections' => [],
        'tables' => [$table],
        'custom' => 'kept',
    ];
    $service->write('floor_test', $layout);
    expect($service->read('floor_test')['custom'])->toBe('kept');
    $layout['tables'][] = $table;
    expect(fn () => $service->write('floor_test', $layout))
        ->toThrow(ValidationException::class);
});

it('uses cards until an enabled layout contains tables and rejects out of bounds', function () {
    Storage::fake('local');
    $type = OrderTypeRow::query()->create([
        'code' => 'lounge',
        'label' => 'Lounge',
        'color' => 'primary',
        'icon' => 'heroicon-o-tag',
        'behavior' => OrderBehavior::DINE_IN,
        'is_active' => true,
        'is_system' => false,
    ]);
    $floor = app(FloorLayoutService::class);
    $behavior = app(DineInBehavior::class);
    expect($behavior->presentation($type)->body)->toBe('cards');
    $table = [
        'number' => '4',
        'capacity' => 4,
        'zone' => '',
        'active' => true,
        'col' => 0,
        'row' => 0,
        'colSpan' => 1,
        'rowSpan' => 1,
    ];
    $layout = [
        'version' => 1,
        'enabled' => false,
        'columns' => 2,
        'rows' => 2,
        'sections' => [],
        'tables' => [$table],
    ];
    $floor->write('lounge', $layout);
    expect($behavior->presentation($type)->body)->toBe('cards');
    $layout['enabled'] = true;
    $floor->write('lounge', $layout);
    expect($behavior->presentation($type)->body)->toBe('floor');
    $layout['tables'][0]['col'] = 2;
    expect(fn () => $floor->write('lounge', $layout))
        ->toThrow(ValidationException::class);
});

it('isolates reservations by type and frees tables outside a layout', function () {
    $tables = app(TableManagementService::class);
    $tables->reserveTable('4', 1, 'dine_in');
    $tables->reserveTable('4', 2, 'lounge');
    expect($tables->isTableAvailable('4', 'dine_in'))->toBeFalse();
    $tables->freeTable('4', 'lounge');
    expect($tables->isTableAvailable('4', 'lounge'))->toBeTrue()
        ->and($tables->isTableAvailable('4', 'dine_in'))->toBeFalse();
});

it('saves through the designer endpoint and rejects non dine-in types', function () {
    Storage::fake('local');
    $user = User::factory()->admin()->create();
    $layout = [
        'version' => 1,
        'enabled' => false,
        'columns' => 2,
        'rows' => 2,
        'sections' => [],
        'tables' => [],
        'custom' => ['value' => 7],
    ];
    $this->actingAs($user)->put(route('floor.save', 'dine_in'), $layout)->assertRedirect();
    expect(app(FloorLayoutService::class)->read('dine_in')['custom'])->toBe(['value' => 7]);
    $this->get(route('floor.designer', 'takeaway'))->assertNotFound();
    $this->put(route('floor.save', 'takeaway'), $layout)->assertNotFound();
    expect(app(FloorLayoutService::class)->isDineInType('takeaway'))->toBeFalse();
    expect(fn () => app(FloorLayoutService::class)->read('takeaway'))
        ->toThrow(InvalidArgumentException::class);
});

it('broadcasts a type change', function () {
    Event::fake([OrderTypeChangedBroadcast::class]);
    $user = User::factory()->create();
    $shift = Shift::factory()->create();
    $order = app(OrderService::class)->createOrder(
        new CreateOrderDTO('takeaway', $shift->id, $user->id),
    );
    app(OrderService::class)->changeOrderType($order->id, 'dine_in', '4');
    Event::assertDispatched(OrderTypeChangedBroadcast::class);
    $this->assertDatabaseHas('dine_tables', [
        'order_type_code' => 'dine_in',
        'table_number' => '4',
        'order_id' => $order->id,
    ]);
});

it('includes floor layout and occupancy in cashier tabs', function () {
    Storage::fake('local');
    $user = User::factory()->admin()->create();
    $shift = Shift::factory()->create([
        'user_id' => $user->id,
        'end_at' => null,
        'closed' => false,
    ]);
    $table = [
        'number' => '4',
        'capacity' => 4,
        'zone' => '',
        'active' => true,
        'col' => 0,
        'row' => 0,
        'colSpan' => 1,
        'rowSpan' => 1,
    ];
    $layout = [
        'version' => 1,
        'enabled' => true,
        'columns' => 2,
        'rows' => 2,
        'sections' => [],
        'tables' => [$table],
    ];
    app(FloorLayoutService::class)->write('dine_in', $layout);
    $order = app(OrderService::class)->createOrder(
        new CreateOrderDTO('dine_in', $shift->id, $user->id, new OrderOpening('4')),
    );
    $response = $this->actingAs($user)->get(route('orders.index'))->assertOk();
    $tabs = $response->viewData('page')['props']['tabs'];
    $tab = collect($tabs)->firstWhere('code', 'dine_in');
    expect($tab['presentation']['body'])->toBe('floor')
        ->and($tab['layout']['tables'][0]['number'])->toBe('4')
        ->and($tab['occupancy'][4]['id'])->toBe($order->id);
});

it('opens the same table number in two dine-in order types', function () {
    OrderTypeRow::query()->create([
        'code' => 'lounge',
        'label' => 'Lounge',
        'color' => 'primary',
        'icon' => 'heroicon-o-tag',
        'behavior' => OrderBehavior::DINE_IN,
        'is_active' => true,
        'is_system' => false,
    ]);
    $user = User::factory()->create();
    $shift = Shift::factory()->create();
    $service = app(OrderService::class);
    foreach (['dine_in', 'lounge'] as $code) {
        $order = $service->createOrder(
            new CreateOrderDTO($code, $shift->id, $user->id, new OrderOpening('4')),
        );
        $this->assertDatabaseHas('dine_tables', [
            'order_type_code' => $code,
            'table_number' => '4',
            'order_id' => $order->id,
        ]);
    }
});

it('passes label and backUrl from the designer show endpoint', function () {
    Storage::fake('local');
    $user = User::factory()->admin()->create();
    $label = OrderTypeRow::query()->where('code', 'dine_in')->value('label');

    $this->actingAs($user)
        ->get(route('floor.designer', 'dine_in'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Floor/Designer')
            ->where('code', 'dine_in')
            ->where('label', $label)
            ->where('backUrl', OrderTypeRowResource::getUrl('index'))
        );
});

it('round-trips a saved table shape and rejects an invalid shape', function () {
    Storage::fake('local');
    $user = User::factory()->admin()->create();
    $layout = [
        'version' => 1,
        'enabled' => true,
        'columns' => 4,
        'rows' => 4,
        'sections' => [],
        'tables' => [
            [
                'number' => '1',
                'capacity' => 4,
                'shape' => 'round',
                'zone' => '',
                'active' => true,
                'col' => 0,
                'row' => 0,
                'colSpan' => 1,
                'rowSpan' => 1,
            ],
        ],
    ];

    $this->actingAs($user)
        ->put(route('floor.save', 'dine_in'), $layout)
        ->assertRedirect();

    expect(app(FloorLayoutService::class)->read('dine_in')['tables'][0]['shape'])->toBe('round');

    $layout['tables'][0]['shape'] = 'hex';
    expect(fn () => app(FloorLayoutService::class)->write('dine_in', $layout))
        ->toThrow(ValidationException::class);
});

it('round-trips tableLabel when saved and rejects invalid tableLabel values', function () {
    Storage::fake('local');
    $user = User::factory()->admin()->create();
    $layout = [
        'version' => 1,
        'enabled' => true,
        'columns' => 4,
        'rows' => 4,
        'sections' => [],
        'tables' => [],
        'tableLabel' => [
            'prefix' => 'T-',
            'showCapacity' => false,
            'showOrderNumber' => true,
            'showTimer' => false,
        ],
    ];

    $this->actingAs($user)
        ->put(route('floor.save', 'dine_in'), $layout)
        ->assertRedirect();

    expect(app(FloorLayoutService::class)->read('dine_in')['tableLabel'])->toBe([
        'prefix' => 'T-',
        'showCapacity' => false,
        'showOrderNumber' => true,
        'showTimer' => false,
    ]);

    $layout['tableLabel']['prefix'] = str_repeat('x', 21);
    expect(fn () => app(FloorLayoutService::class)->write('dine_in', $layout))
        ->toThrow(ValidationException::class);

    $layout['tableLabel']['prefix'] = 'طاولة';
    $layout['tableLabel']['showCapacity'] = 'not-a-boolean';
    expect(fn () => app(FloorLayoutService::class)->write('dine_in', $layout))
        ->toThrow(ValidationException::class);
});

it('does not send a list of tables outside the layout to the designer', function () {
    Storage::fake('local');
    $user = User::factory()->admin()->create();

    app(FloorLayoutService::class)->write('dine_in', [
        'version' => 1,
        'enabled' => true,
        'columns' => 4,
        'rows' => 4,
        'sections' => [],
        'tables' => [
            [
                'number' => '1',
                'capacity' => 4,
                'zone' => '',
                'active' => true,
                'col' => 0,
                'row' => 0,
                'colSpan' => 1,
                'rowSpan' => 1,
            ],
        ],
    ]);

    DineTable::query()->create([
        'order_type_code' => 'dine_in',
        'table_number' => '1',
        'order_id' => null,
    ]);
    DineTable::query()->create([
        'order_type_code' => 'dine_in',
        'table_number' => 'تراس - 3',
        'order_id' => null,
    ]);

    $this->actingAs($user)
        ->get(route('floor.designer', 'dine_in'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Floor/Designer')
            ->missing('outsideTables')
        );
});
