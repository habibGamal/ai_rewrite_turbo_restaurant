<?php

use App\Core\Events\OrderChargesChanged;
use App\Enums\AuditAction;
use App\Enums\OptionalFeature;
use App\Enums\OrderStatus;
use App\Events\Orders\OrderChargesChangedBroadcast;
use App\Exceptions\OrderException;
use App\Filament\Pages\Reports\EquipmentReport;
use App\Filament\Resources\Equipment\EquipmentResource;
use App\Filament\Resources\OrderTypeRows\OrderTypeRowResource;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\OrderEquipment;
use App\Models\OrderTypeRow;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Services\FeatureService;
use App\Services\Orders\OrderEquipmentService;
use App\Services\Orders\OrderService;
use App\Services\Reports\EquipmentReportService;
use App\Sync\Builders\OrderBuilder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(FeatureService::class)->enable(OptionalFeature::EQUIPMENT);
    $this->user = User::factory()->admin()->create();
    actingAs($this->user);
    $this->shift = Shift::factory()->active()->create(['user_id' => $this->user->id]);
    $this->product = Product::factory()->create(['price' => 100, 'cost' => 40]);
    $this->shisha = Equipment::create(['name' => 'شيشة', 'usage_price' => 50, 'usage_cost' => 15, 'is_active' => true]);
    $this->ps = Equipment::create(['name' => 'بلايستيشن', 'usage_price' => 80, 'usage_cost' => 0, 'is_active' => true]);
    $this->takeaway = OrderTypeRow::query()->where('code', 'takeaway')->firstOrFail();
    $this->takeaway->equipment()->sync([$this->shisha->id, $this->ps->id]);
});

function equipmentOrder(array $attributes = []): Order
{
    $order = Order::factory()->create([
        'user_id' => test()->user->id,
        'shift_id' => test()->shift->id,
        'type' => 'takeaway',
        'status' => OrderStatus::PROCESSING,
        'discount' => 0,
        'temp_discount_percent' => 0,
        ...$attributes,
    ]);
    $order->items()->create([
        'product_id' => test()->product->id,
        'quantity' => 1,
        'price' => 100,
        'cost' => 40,
        'total' => 100,
    ]);

    return $order;
}

function equipmentService(): OrderEquipmentService
{
    return app(OrderEquipmentService::class);
}

it('adds equipment to the order total and takes its cost from profit', function () {
    Event::fake([OrderChargesChangedBroadcast::class, OrderChargesChanged::class]);
    $order = equipmentOrder();

    $order = equipmentService()->sync($order->id, [test()->shisha->id => 2, test()->ps->id => 1]);

    expect($order->total)->toBe('280.00') // 100 items + 2×50 + 80
        ->and($order->equipment_total)->toBe('180.00')
        ->and($order->equipment_cost)->toBe('30.00')
        ->and($order->profit)->toBe('210.00') // 280 − 40 items − 30 equipment
        ->and($order->equipmentLines()->count())->toBe(2);

    $line = $order->equipmentLines()->where('equipment_id', test()->shisha->id)->first();
    expect($line->name)->toBe('شيشة')
        ->and($line->quantity)->toBe(2)
        ->and($line->unit_price)->toBe('50.00')
        ->and($line->user_id)->toBe(test()->user->id);

    Event::assertDispatched(OrderChargesChanged::class, fn ($event) => $event->kind === OrderChargesChanged::EQUIPMENT);
});

it('keeps the snapshot price of a line when the catalogue price changes', function () {
    $order = equipmentOrder();
    equipmentService()->sync($order->id, [test()->shisha->id => 1]);
    test()->shisha->update(['usage_price' => 70]);

    $order = equipmentService()->sync($order->id, [test()->shisha->id => 2]);

    expect($order->equipment_total)->toBe('100.00');
});

it('does not apply service or order discount to equipment', function () {
    $order = equipmentOrder(['temp_discount_percent' => 10]);

    $order = equipmentService()->sync($order->id, [test()->ps->id => 1]);

    expect($order->discount)->toBe('10.00')
        ->and($order->total)->toBe('170.00'); // 100 − 10 + 80
});

it('refuses equipment the order type does not allow', function () {
    $hookah = Equipment::create(['name' => 'دفاية', 'usage_price' => 30, 'usage_cost' => 0, 'is_active' => true]);
    $order = equipmentOrder();

    expect(fn () => equipmentService()->sync($order->id, [$hookah->id => 1]))
        ->toThrow(OrderException::class, 'هذه المعدة غير متاحة لهذا النوع من الطلبات');

    test()->shisha->update(['is_active' => false]);
    expect(fn () => equipmentService()->sync($order->id, [test()->shisha->id => 1]))->toThrow(OrderException::class);
});

it('refuses changes on a completed order and while the feature is off', function () {
    $order = equipmentOrder(['status' => OrderStatus::COMPLETED]);
    expect(fn () => equipmentService()->sync($order->id, [test()->shisha->id => 1]))->toThrow(OrderException::class);

    app(FeatureService::class)->disable(OptionalFeature::EQUIPMENT);
    $open = equipmentOrder();
    expect(fn () => equipmentService()->sync($open->id, [test()->shisha->id => 1]))->toThrow(OrderException::class);
});

it('needs order.remove_equipment to lower or remove a line and audits it', function () {
    $cashier = User::factory()->cashier()->create();
    actingAs($cashier);
    $order = equipmentOrder();

    equipmentService()->sync($order->id, [test()->shisha->id => 3]);
    expect(fn () => equipmentService()->sync($order->id, [test()->shisha->id => 1]))
        ->toThrow(OrderException::class, 'ليس لديك صلاحية حذف أو تقليل المعدات');
    $line = $order->equipmentLines()->first();
    expect(fn () => equipmentService()->removeLine($order->id, $line->id))->toThrow(OrderException::class);

    $cashier->givePermissionTo('order.remove_equipment');
    equipmentService()->sync($order->id, [test()->shisha->id => 1]);
    $order = equipmentService()->removeLine($order->id, $line->id);

    expect($order->equipment_total)->toBe('0.00')
        ->and($order->total)->toBe('100.00')
        ->and(OrderEquipment::count())->toBe(0);

    $audits = AuditLog::query()->where('context', OrderEquipmentService::AUDIT_CONTEXT)->orderBy('id')->get();
    expect($audits)->toHaveCount(2)
        ->and($audits[0]->action)->toBe(AuditAction::UPDATED)
        ->and($audits[0]->old_values['quantity'])->toBe(3)
        ->and($audits[0]->new_values['quantity'])->toBe(1)
        ->and($audits[0]->shift_id)->toBe(test()->shift->id)
        ->and($audits[1]->action)->toBe(AuditAction::DELETED);
});

it('does not let a cashier without the permission add equipment', function () {
    expect(Role::findByName('cashier')->hasPermissionTo('order.add_equipment'))->toBeTrue()
        ->and(Role::findByName('cashier')->hasPermissionTo('order.remove_equipment'))->toBeFalse();
    Role::findByName('cashier')->revokePermissionTo('order.add_equipment');
    $user = User::factory()->cashier()->create();
    actingAs($user);

    expect(fn () => equipmentService()->sync(equipmentOrder()->id, [test()->shisha->id => 1]))
        ->toThrow(OrderException::class, 'ليس لديك صلاحية إضافة معدات للطلب');
});

it('refuses an order type change to a type that does not allow the equipment', function () {
    $order = equipmentOrder();
    equipmentService()->sync($order->id, [test()->shisha->id => 1]);

    expect(fn () => app(OrderService::class)->changeOrderType($order->id, 'delivery'))
        ->toThrow(OrderException::class);

    OrderTypeRow::query()->where('code', 'delivery')->firstOrFail()->equipment()->attach(test()->shisha->id);
    app(OrderService::class)->changeOrderType($order->id, 'delivery');

    expect($order->fresh()->type)->toBe('delivery');
});

it('refuses switching the feature off while an open order carries equipment', function () {
    $order = equipmentOrder();
    equipmentService()->sync($order->id, [test()->shisha->id => 1]);

    $blockers = app(FeatureService::class)->checkDisableBlockers(OptionalFeature::EQUIPMENT);
    expect($blockers)->not->toBeEmpty();

    $order->update(['status' => OrderStatus::COMPLETED]);
    expect(app(FeatureService::class)->checkDisableBlockers(OptionalFeature::EQUIPMENT))->toBe([]);
});

it('saves equipment from the cashier screen and sends it to the manage page', function () {
    $order = equipmentOrder();

    $this->post(route('orders.equipment.save', $order), [
        'lines' => [['equipment_id' => test()->shisha->id, 'quantity' => 2]],
    ])->assertSessionHasNoErrors();

    expect($order->fresh()->equipment_total)->toBe('100.00');

    get(route('orders.manage', $order))->assertInertia(fn (Assert $page) => $page
        ->has('equipmentLines', 1)
        ->where('equipmentLines.0.name', 'شيشة')
        ->has('equipmentOptions', 2)
        ->where('order.equipment_total', '100.00')
        ->has('order.receiptView.equipment_lines', 1));

    $line = $order->equipmentLines()->first();
    $this->delete(route('orders.equipment.remove', [$order, $line->id]))->assertSessionHasNoErrors();
    expect($order->fresh()->equipment_total)->toBe('0.00');
});

it('sends no equipment options while the feature is off', function () {
    app(FeatureService::class)->disable(OptionalFeature::EQUIPMENT);
    $order = equipmentOrder();

    get(route('orders.manage', $order))->assertInertia(fn (Assert $page) => $page
        ->has('equipmentOptions', 0)
        ->has('equipmentLines', 0));

    $this->post(route('orders.equipment.save', $order), [
        'lines' => [['equipment_id' => test()->shisha->id, 'quantity' => 1]],
    ])->assertSessionHasErrors('error');
    expect($order->fresh()->equipmentLines()->count())->toBe(0);
});

it('completes an order with equipment and reports it', function () {
    $order = equipmentOrder();
    equipmentService()->sync($order->id, [test()->shisha->id => 2]);
    $order->update(['status' => OrderStatus::COMPLETED]);
    $other = equipmentOrder();
    equipmentService()->sync($other->id, [test()->ps->id => 1]); // still open: not reported

    $summary = app(EquipmentReportService::class)->summary([test()->shift->id]);
    expect($summary)->toBe(['uses' => 2, 'revenue' => 100.0, 'cost' => 30.0, 'profit' => 70.0]);

    $rows = app(EquipmentReportService::class)->perEquipmentQuery([test()->shift->id])->get()->keyBy('name');
    expect((int) $rows['شيشة']->uses)->toBe(2)
        ->and((float) $rows['شيشة']->revenue)->toBe(100.0)
        ->and((int) $rows['بلايستيشن']->uses)->toBe(0);

    expect(app(EquipmentReportService::class)->summary([test()->shift->id], 'delivery')['uses'])->toBe(0);
});

it('syncs the equipment lines with the order', function () {
    $order = equipmentOrder();
    equipmentService()->sync($order->id, [test()->shisha->id => 2]);

    $payload = app(OrderBuilder::class)->build((string) $order->fresh()->uuid);

    expect($payload['equipment_total'])->toBe('100.00')
        ->and($payload['equipment'])->toHaveCount(1)
        ->and($payload['equipment'][0]['name'])->toBe('شيشة')
        ->and($payload['equipment'][0]['quantity'])->toBe(2);
});

it('hides the equipment screens and permissions while the feature is off', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    get(EquipmentResource::getUrl('index', panel: 'admin'))->assertOk();
    get(EquipmentReport::getUrl(panel: 'admin'))->assertOk();

    app(FeatureService::class)->disable(OptionalFeature::EQUIPMENT);

    get(EquipmentResource::getUrl('index', panel: 'admin'))->assertNotFound();
    get(EquipmentReport::getUrl(panel: 'admin'))->assertNotFound();
    expect(test()->user->can('order.add_equipment'))->toBeFalse()
        ->and(test()->user->can('view_equipment'))->toBeFalse();
});

it('lists allowed equipment in the order type panel only while the feature is on', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $url = OrderTypeRowResource::getUrl('edit', ['record' => test()->takeaway], panel: 'admin');

    get($url)->assertOk()->assertSee('شيشة')->assertSee('المعدات التي يمكن للكاشير إضافتها');
    get(EquipmentResource::getUrl('edit', ['record' => test()->shisha], panel: 'admin'))->assertOk();

    app(FeatureService::class)->disable(OptionalFeature::EQUIPMENT);
    get($url)->assertOk()->assertDontSee('المعدات التي يمكن للكاشير إضافتها');
});
