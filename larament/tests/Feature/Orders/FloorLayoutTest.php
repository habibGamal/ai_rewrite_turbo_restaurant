<?php

use App\Enums\OrderBehavior;
use App\Models\OrderTypeRow;
use App\Services\Orders\FloorLayoutService;
use Illuminate\Support\Facades\Storage;

it('keeps unknown layout keys and rejects duplicate tables', function () {
    Storage::fake('local');
    OrderTypeRow::query()->create(['code' => 'floor_test', 'label' => 'Floor', 'color' => 'primary', 'icon' => 'heroicon-o-tag', 'behavior' => OrderBehavior::DINE_IN, 'is_active' => true, 'is_system' => false]);
    $service = app(FloorLayoutService::class);
    $table = ['number' => '4', 'capacity' => 4, 'zone' => '', 'active' => true, 'col' => 0, 'row' => 0, 'colSpan' => 1, 'rowSpan' => 1];
    $layout = ['version' => 1, 'enabled' => true, 'columns' => 2, 'rows' => 2, 'sections' => [], 'tables' => [$table], 'custom' => 'kept'];
    $service->write('floor_test', $layout);
    expect($service->read('floor_test')['custom'])->toBe('kept');
    $layout['tables'][] = $table;
    expect(fn () => $service->write('floor_test', $layout))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('uses cards until an enabled layout contains tables and rejects out of bounds', function () {
    Storage::fake('local');
    $type = OrderTypeRow::query()->create(['code' => 'lounge', 'label' => 'Lounge', 'color' => 'primary', 'icon' => 'heroicon-o-tag', 'behavior' => OrderBehavior::DINE_IN, 'is_active' => true, 'is_system' => false]);
    $floor = app(FloorLayoutService::class);
    $behavior = app(\App\Orders\Behaviors\DineInBehavior::class);
    expect($behavior->presentation($type)->body)->toBe('cards');
    $layout = ['version' => 1, 'enabled' => false, 'columns' => 2, 'rows' => 2, 'sections' => [], 'tables' => [['number' => '4', 'capacity' => 4, 'zone' => '', 'active' => true, 'col' => 0, 'row' => 0, 'colSpan' => 1, 'rowSpan' => 1]]];
    $floor->write('lounge', $layout);
    expect($behavior->presentation($type)->body)->toBe('cards');
    $layout['enabled'] = true;
    $floor->write('lounge', $layout);
    expect($behavior->presentation($type)->body)->toBe('floor');
    $layout['tables'][0]['col'] = 2;
    expect(fn () => $floor->write('lounge', $layout))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('isolates reservations by type and frees tables outside a layout', function () {
    $tables = app(\App\Services\Orders\TableManagementService::class);
    $tables->reserveTable('4', 1, 'dine_in');
    $tables->reserveTable('4', 2, 'lounge');
    expect($tables->isTableAvailable('4', 'dine_in'))->toBeFalse();
    $tables->freeTable('4', 'lounge');
    expect($tables->isTableAvailable('4', 'lounge'))->toBeTrue()
        ->and($tables->isTableAvailable('4', 'dine_in'))->toBeFalse();
});

it('saves through the designer endpoint and rejects non dine-in types', function () {
    Storage::fake('local');
    $user = \App\Models\User::factory()->admin()->create();
    $layout = ['version' => 1, 'enabled' => false, 'columns' => 2, 'rows' => 2, 'sections' => [], 'tables' => [], 'custom' => ['value' => 7]];
    $this->actingAs($user)->put(route('floor.save', 'dine_in'), $layout)->assertRedirect();
    expect(app(FloorLayoutService::class)->read('dine_in')['custom'])->toBe(['value' => 7]);
    $this->get(route('floor.designer', 'takeaway'))->assertNotFound();
    $this->put(route('floor.save', 'takeaway'), $layout)->assertNotFound();
    expect(app(FloorLayoutService::class)->isDineInType('takeaway'))->toBeFalse();
    expect(fn () => app(FloorLayoutService::class)->read('takeaway'))
        ->toThrow(\InvalidArgumentException::class);
});

it('broadcasts a type change', function () {
    \Illuminate\Support\Facades\Event::fake([\App\Events\Orders\OrderTypeChanged::class]);
    $user = \App\Models\User::factory()->create();
    $shift = \App\Models\Shift::factory()->create();
    $order = app(\App\Services\Orders\OrderService::class)->createOrder(new \App\DTOs\Orders\CreateOrderDTO('takeaway', $shift->id, $user->id));
    app(\App\Services\Orders\OrderService::class)->changeOrderType($order->id, 'dine_in', '4');
    \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\Orders\OrderTypeChanged::class);
    $this->assertDatabaseHas('dine_tables', ['order_type_code' => 'dine_in', 'table_number' => '4', 'order_id' => $order->id]);
});

it('includes floor layout and occupancy in cashier tabs', function () {
    Storage::fake('local');
    $user = \App\Models\User::factory()->admin()->create();
    $shift = \App\Models\Shift::factory()->create(['user_id' => $user->id, 'end_at' => null, 'closed' => false]);
    $layout = ['version' => 1, 'enabled' => true, 'columns' => 2, 'rows' => 2, 'sections' => [], 'tables' => [['number' => '4', 'capacity' => 4, 'zone' => '', 'active' => true, 'col' => 0, 'row' => 0, 'colSpan' => 1, 'rowSpan' => 1]]];
    app(FloorLayoutService::class)->write('dine_in', $layout);
    $order = app(\App\Services\Orders\OrderService::class)->createOrder(new \App\DTOs\Orders\CreateOrderDTO('dine_in', $shift->id, $user->id, new \App\Orders\OrderOpening('4')));
    $response = $this->actingAs($user)->get(route('orders.index'))->assertOk();
    $tabs = $response->viewData('page')['props']['tabs'];
    $tab = collect($tabs)->firstWhere('code', 'dine_in');
    expect($tab['presentation']['body'])->toBe('floor')
        ->and($tab['layout']['tables'][0]['number'])->toBe('4')
        ->and($tab['occupancy'][4]['id'])->toBe($order->id);
});

it('opens the same table number in two dine-in order types', function () {
    OrderTypeRow::query()->create(['code' => 'lounge', 'label' => 'Lounge', 'color' => 'primary', 'icon' => 'heroicon-o-tag', 'behavior' => OrderBehavior::DINE_IN, 'is_active' => true, 'is_system' => false]);
    $user = \App\Models\User::factory()->create();
    $shift = \App\Models\Shift::factory()->create();
    $service = app(\App\Services\Orders\OrderService::class);
    foreach (['dine_in', 'lounge'] as $code) {
        $order = $service->createOrder(new \App\DTOs\Orders\CreateOrderDTO($code, $shift->id, $user->id, new \App\Orders\OrderOpening('4')));
        $this->assertDatabaseHas('dine_tables', ['order_type_code' => $code, 'table_number' => '4', 'order_id' => $order->id]);
    }
});
