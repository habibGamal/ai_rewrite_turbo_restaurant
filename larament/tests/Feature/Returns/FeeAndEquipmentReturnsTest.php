<?php

use App\Enums\OptionalFeature;
use App\Enums\ReturnStatus;
use App\Enums\SettingKey;
use App\Filament\Actions\ReturnOrderAction;
use App\Filament\Forms\ReturnChargeLines;
use App\Filament\Resources\OrderReturns\Pages\CreateOrderReturn;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\OrderTypeRow;
use App\Models\Shift;
use App\Services\CustomersPerformanceReportService;
use App\Services\CustomFeesReportService;
use App\Services\FeatureService;
use App\Services\Orders\OrderEquipmentService;
use App\Services\Orders\OrderFeeService;
use App\Services\Orders\OrderReturnService;
use App\Services\Orders\OrderService;
use App\Services\Reports\EquipmentReportService;
use App\Services\Reports\ReturnsAggregates;
use App\Services\SettingsService;
use App\Services\ShiftsReportService;
use App\Sync\Builders\OrderReturnBuilder;
use Tests\Support\SafetyNet;

beforeEach(function () {
    app(FeatureService::class)->enable(OptionalFeature::CUSTOM_FEES);
    app(FeatureService::class)->enable(OptionalFeature::EQUIPMENT);
    app(SettingsService::class)->set(SettingKey::DINE_IN_SERVICE_CHARGE->value, '0');
    ['user' => $this->user, 'shift' => $this->shift] = SafetyNet::till();

    $this->shisha = Equipment::create(['name' => 'شيشة', 'usage_price' => 50, 'usage_cost' => 15, 'is_active' => true]);
    OrderTypeRow::query()->where('code', 'takeaway')->firstOrFail()->equipment()->sync([$this->shisha->id]);

    $this->burger = SafetyNet::product(price: 100, cost: 40, stock: 10);
    $order = SafetyNet::open($this->shift, $this->user);
    SafetyNet::save($order, [[$this->burger, 1]]);
    app(OrderFeeService::class)->add($order->id, [['name' => 'رسوم دخول تورتة', 'amount' => 30]]);
    app(OrderEquipmentService::class)->sync($order->id, [$this->shisha->id => 2]);

    // 100 items + 30 fee + 100 equipment
    $this->order = app(OrderService::class)->completeOrder($order->id, ['cash' => 230]);
    $this->itemLine = $this->order->items()->sole();
    $this->feeLine = $this->order->fees()->sole();
    $this->equipmentLine = $this->order->equipmentLines()->sole();
    $this->returns = app(OrderReturnService::class);
});

function refundCharges(array $lines, ?Order $order = null): OrderReturn
{
    $amount = array_sum(array_column($lines, 'refund_amount'));

    return test()->returns->processReturn(
        $order ?? test()->order->fresh(),
        $lines,
        'test',
        [['method' => 'cash', 'amount' => $amount]],
        test()->shift->id,
    );
}

it('refunds part of a fee by amount and the rest later, without touching stock', function () {
    expect((float) $this->order->total)->toBe(230.0);

    $first = refundCharges([['order_fee_id' => $this->feeLine->id, 'refund_amount' => 10]]);
    $line = $first->items()->sole();

    expect($line->kind())->toBe(OrderReturnItem::KIND_FEE)
        ->and((float) $line->quantity)->toBe(0.0)
        ->and($line->restocked)->toBeFalse()
        ->and($first->reverse_stock)->toBeFalse()
        ->and(SafetyNet::stock($this->burger))->toBe(9.0)
        ->and($this->order->fresh()->return_status)->toBe(ReturnStatus::PARTIAL_RETURN)
        ->and($this->returns->getRefundableFeeAmount($this->order->fresh(), $this->feeLine->id))->toBe(20.0);

    expect(fn () => refundCharges([['order_fee_id' => $this->feeLine->id, 'refund_amount' => 25]]))
        ->toThrow(Exception::class, 'أكبر من المتاح');

    refundCharges([['order_fee_id' => $this->feeLine->id, 'refund_amount' => 20]]);
    expect($this->returns->getRefundableFeeAmount($this->order->fresh(), $this->feeLine->id))->toBe(0.0);
});

it('refunds equipment by quantity, capped at what is left and at qty × price', function () {
    expect(fn () => refundCharges([['order_equipment_id' => $this->equipmentLine->id, 'quantity' => 1, 'refund_amount' => 60]]))
        ->toThrow(Exception::class, 'أكبر من قيمتها');

    // Editable down: 1 use refunded for 40 instead of 50.
    refundCharges([['order_equipment_id' => $this->equipmentLine->id, 'quantity' => 1, 'refund_amount' => 40]]);

    expect($this->returns->getAvailableEquipmentQuantity($this->order->fresh(), $this->equipmentLine->id))->toBe(1);
    expect(fn () => refundCharges([['order_equipment_id' => $this->equipmentLine->id, 'quantity' => 2, 'refund_amount' => 100]]))
        ->toThrow(Exception::class, 'أكبر من المتاح');
    expect(fn () => refundCharges([['order_equipment_id' => $this->equipmentLine->id, 'quantity' => 0.5, 'refund_amount' => 10]]))
        ->toThrow(Exception::class, 'عدداً صحيحاً');

    // Equipment cost stays on the order: the order's profit is untouched.
    expect((float) $this->order->fresh()->equipment_cost)->toBe(30.0);
});

it('marks the order fully returned only when items, fees and equipment are all refunded', function () {
    refundCharges([['order_item_id' => $this->itemLine->id, 'quantity' => 1, 'refund_amount' => 100]]);
    expect($this->order->fresh()->return_status)->toBe(ReturnStatus::PARTIAL_RETURN);

    refundCharges([
        ['order_fee_id' => $this->feeLine->id, 'refund_amount' => 30],
        ['order_equipment_id' => $this->equipmentLine->id, 'quantity' => 2, 'refund_amount' => 100],
    ]);
    expect($this->order->fresh()->return_status)->toBe(ReturnStatus::FULL_RETURN);
});

it('rejects a fee or equipment line from another order', function () {
    $other = SafetyNet::open($this->shift, $this->user);
    SafetyNet::save($other, [[$this->burger, 1]]);
    app(OrderFeeService::class)->add($other->id, [['name' => 'أخرى', 'amount' => 5]]);
    $otherFee = $other->fees()->sole();

    expect(fn () => refundCharges([['order_fee_id' => $otherFee->id, 'refund_amount' => 5]]))
        ->toThrow(Exception::class, 'الرسوم غير موجودة');
});

it('refuses a returned line that points to nothing or to two kinds', function () {
    expect(fn () => OrderReturnItem::create(['order_return_id' => 1, 'quantity' => 1, 'refund_amount' => 1]))
        ->toThrow(LogicException::class);
});

it('splits refunds by kind and shows refunded and net in the fees and equipment reports', function () {
    refundCharges([
        ['order_item_id' => $this->itemLine->id, 'quantity' => 1, 'refund_amount' => 100],
        ['order_fee_id' => $this->feeLine->id, 'refund_amount' => 10],
        ['order_equipment_id' => $this->equipmentLine->id, 'quantity' => 1, 'refund_amount' => 50],
    ]);

    $returns = app(ReturnsAggregates::class)->forShifts([$this->shift->id]);
    expect($returns['returnsAmount'])->toBe(160.0)
        ->and($returns['itemsRefunds'])->toBe(100.0)
        ->and($returns['feesRefunds'])->toBe(10.0)
        ->and($returns['equipmentRefunds'])->toBe(50.0)
        ->and($returns['restockedQuantity'])->toBe(1.0)
        ->and($returns['damagedQuantity'])->toBe(0.0)
        // item: 100 − 40 cost back; fee 10 and equipment 50 lose their whole refund
        ->and($returns['returnsProfitImpact'])->toBe(120.0);

    $fees = app(CustomFeesReportService::class)->summaryByName([$this->shift->id]);
    expect(array_values($fees)[0])->toMatchArray(['amount' => 30.0, 'refunded' => 10.0, 'net' => 20.0]);

    $equipment = app(EquipmentReportService::class)->summary([$this->shift->id]);
    expect($equipment)->toMatchArray([
        'revenue' => 100.0, 'cost' => 30.0, 'refunded_uses' => 1, 'refunded' => 50.0, 'net_revenue' => 50.0, 'net_profit' => 20.0,
    ]);
    $row = app(EquipmentReportService::class)->perEquipmentQuery([$this->shift->id])->whereKey($this->shisha->id)->first();
    expect((float) $row->refunded)->toBe(50.0)->and((int) $row->refunded_uses)->toBe(1);

    $stats = app(ShiftsReportService::class)->calculateShiftStats($this->shift->fresh());
    expect($stats['feesRefunds'])->toBe(10.0)
        ->and($stats['equipmentRefunds'])->toBe(50.0)
        ->and($stats['netSales'])->toBe(round((float) $stats['sales'] - 160, 2));

    // Grouped reports (by order type) keep fee and equipment refunds.
    $byType = app(ReturnsAggregates::class)->groupedBy(app(ReturnsAggregates::class)->periodQuery(null, null), 'ro.type');
    expect($byType['takeaway']['amount'])->toBe(160.0)->and($byType['takeaway']['quantity'])->toBe(1.0);

    $facts = app(CustomersPerformanceReportService::class)->orderFactsQuery()->where('orders.id', $this->order->id)->first();
    expect((float) $facts->refund)->toBe(160.0)->and((float) $facts->net_qty)->toBe(0.0);
});

it('counts a fee refund paid in a later shift in that shift, by name', function () {
    $this->shift->update(['closed' => true, 'end_at' => now()]);
    $later = Shift::factory()->active()->create(['user_id' => $this->user->id]);

    $this->returns->processReturn($this->order->fresh(), [['order_fee_id' => $this->feeLine->id, 'refund_amount' => 30]], 'late', [['method' => 'cash', 'amount' => 30]], $later->id);

    $summary = app(CustomFeesReportService::class)->summaryByName([$later->id]);
    expect(array_values($summary)[0])->toMatchArray(['name' => 'رسوم دخول تورتة', 'amount' => 0.0, 'refunded' => 30.0, 'net' => -30.0]);
});

it('sends the line kind and fee or equipment uuid in the return sync payload', function () {
    $return = refundCharges([
        ['order_fee_id' => $this->feeLine->id, 'refund_amount' => 10],
        ['order_equipment_id' => $this->equipmentLine->id, 'quantity' => 1, 'refund_amount' => 50],
    ]);

    $payload = app(OrderReturnBuilder::class)->build($return->uuid);
    expect(collect($payload['items'])->pluck('kind')->all())->toBe(['fee', 'equipment'])
        ->and($payload['items'][0]['order_fee_uuid'])->toBe($this->feeLine->uuid)
        ->and($payload['items'][1]['order_equipment_uuid'])->toBe($this->equipmentLine->uuid)
        ->and($payload['items'][0]['order_item_uuid'])->toBeNull();
});

it('collects fee and equipment rows from the return form', function () {
    $lines = ReturnChargeLines::fromFormData([
        'return_fees' => [
            ['order_fee_id' => $this->feeLine->id, 'refund_amount' => '12.5'],
            ['order_fee_id' => 999, 'refund_amount' => 0],
        ],
        'return_equipment' => [
            ['order_equipment_id' => $this->equipmentLine->id, 'quantity' => '1', 'refund_amount' => '50'],
            ['order_equipment_id' => 999, 'quantity' => 0, 'refund_amount' => 0],
        ],
    ]);

    expect($lines)->toBe([
        ['order_fee_id' => $this->feeLine->id, 'refund_amount' => 12.5],
        ['order_equipment_id' => $this->equipmentLine->id, 'quantity' => 1, 'refund_amount' => 50.0],
    ]);

    $defaults = ReturnChargeLines::defaults($this->order->fresh());
    expect($defaults['return_fees'][0])->toMatchArray(['refundable' => 30.0])
        ->and($defaults['return_equipment'][0])->toMatchArray(['available_quantity' => 2, 'unit_price' => 50.0]);
    expect(ReturnOrderAction::make())->not->toBeNull();
});

it('refunds a fee and an equipment use from the admin create-return page', function () {
    session(['current_shift_id' => $this->shift->id]);

    $page = Pest\Livewire\livewire(CreateOrderReturn::class)
        ->fillForm(['order_id' => $this->order->id]);

    $fees = $page->get('data.return_fees');
    $equipment = $page->get('data.return_equipment');
    $feeKey = array_key_first($fees);
    $equipmentKey = array_key_first($equipment);
    $distributionKey = array_key_first($page->get('data.refund_distribution'));

    $page->fillForm([
        "return_fees.{$feeKey}.refund_amount" => 30,
        "return_equipment.{$equipmentKey}.quantity" => 1,
        "return_equipment.{$equipmentKey}.refund_amount" => 50,
        "refund_distribution.{$distributionKey}.amount" => 80,
        'reason' => 'لم تُستخدم',
    ])->call('create')->assertHasNoFormErrors();

    $return = $this->order->fresh()->returns()->sole();
    expect((float) $return->total_refund)->toBe(80.0)
        ->and($return->items->map->kind()->sort()->values()->all())->toBe(['equipment', 'fee']);
});

it('does not let two lines in one return over-refund the same fee or equipment', function () {
    expect(fn () => refundCharges([
        ['order_fee_id' => $this->feeLine->id, 'refund_amount' => 20],
        ['order_fee_id' => $this->feeLine->id, 'refund_amount' => 20],
    ]))->toThrow(Exception::class, 'أكبر من المتاح');

    expect(fn () => refundCharges([
        ['order_equipment_id' => $this->equipmentLine->id, 'quantity' => 2, 'refund_amount' => 100],
        ['order_equipment_id' => $this->equipmentLine->id, 'quantity' => 1, 'refund_amount' => 50],
    ]))->toThrow(Exception::class, 'أكبر من المتاح');

    expect($this->order->fresh()->returns()->count())->toBe(0);
});
