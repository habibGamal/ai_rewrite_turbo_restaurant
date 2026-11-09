<?php

use App\Enums\OrderBehavior;
use App\Enums\OrderStatus;
use App\Enums\SettingKey;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\DineTable;
use App\Models\Order;
use App\Models\OrderTypeRow;
use App\Models\Shift;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

function customOrderType(string $code, string $label, OrderBehavior $behavior, bool $active = true): OrderTypeRow
{
    return OrderTypeRow::query()->create([
        'code' => $code,
        'label' => $label,
        'color' => 'info',
        'icon' => 'heroicon-o-sparkles',
        'is_active' => $active,
        'is_system' => false,
        'behavior' => $behavior,
    ]);
}

describe('dynamic order types', function () {
    beforeEach(function () {
        $this->user = User::factory()->admin()->create();
        $this->shift = Shift::factory()->create([
            'user_id' => $this->user->id,
            'end_at' => null,
            'closed' => false,
        ]);
        actingAs($this->user);
    });

    it('creates and completes a cashier order for an admin-created counter type', function () {
        customOrderType('catering', 'كيترنج', OrderBehavior::COUNTER);

        $response = $this->post(route('orders.store'), [
            'type' => 'catering',
        ]);

        $order = Order::query()->where('type', 'catering')->first();

        expect($order)->not->toBeNull()
            ->and($order->order_number)->not->toBeNull()
            ->and((int) $order->order_number)->toBeGreaterThan(0);

        $response->assertRedirect(route('orders.manage', $order));

        $this->post(route('orders.complete', $order), [
            'cash' => 0,
            'card' => 0,
            'talabat_card' => 0,
            'print' => false,
        ])->assertRedirect()
            ->assertSessionHas('success', 'تم إنهاء الطلب بنجاح');

        expect($order->fresh()->status)->toBe(OrderStatus::COMPLETED);
    });

    it('requires a table for a custom dine-in type and prints the table line', function () {
        customOrderType('lounge', 'لاونج', OrderBehavior::DINE_IN);

        $this->post(route('orders.store'), [
            'type' => 'lounge',
        ])->assertSessionHasErrors([
            'error' => 'حدث خطأ أثناء إنشاء الطلب: رقم الطاولة مطلوب',
        ]);

        expect(Order::query()->where('type', 'lounge')->exists())->toBeFalse();

        $created = $this->post(route('orders.store'), [
            'type' => 'lounge',
            'table_number' => 'L4',
        ]);

        $order = Order::query()->where('type', 'lounge')->first();

        expect($order)->not->toBeNull()
            ->and($order->dine_table_number)->toBe('L4')
            ->and(DineTable::query()->where('table_number', 'L4')->where('order_id', $order->id)->exists())->toBeTrue();

        $created->assertRedirect(route('orders.manage', $order));

        $html = view('print.receipt-template', ['order' => $order->load('items')])->render();

        expect($html)->toContain('طاولة رقم L4')
            ->and($html)->toContain('لاونج');
    });

    it('rejects web delivery and inactive codes from the cashier', function () {
        customOrderType('banquet', 'مأدبة', OrderBehavior::COUNTER, active: false);

        $this->post(route('orders.store'), [
            'type' => 'web_delivery',
        ])->assertSessionHasErrors('type');

        $this->post(route('orders.store'), [
            'type' => 'banquet',
        ])->assertSessionHasErrors('type');

        expect(Order::query()->count())->toBe(0);
    });

    it('rejects changing an order to an inactive type', function () {
        customOrderType('banquet', 'مأدبة', OrderBehavior::COUNTER, active: false);

        $order = Order::factory()->create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'type' => 'takeaway',
            'status' => OrderStatus::PROCESSING,
        ]);

        $this->post(route('orders.updateType', $order), [
            'type' => 'banquet',
        ])->assertSessionHasErrors('type');

        expect($order->fresh()->type)->toBe('takeaway');
    });

    it('shows a kds target field for an active custom type', function () {
        customOrderType('catering', 'كيترنج', OrderBehavior::COUNTER);

        livewire(Settings::class)
            ->assertSuccessful()
            ->assertSchemaComponentExists(SettingKey::KDS_ORDER_TYPE_TARGETS->value.'.catering');
    });

    it('renders the catalog label for a custom type on the order resource table', function () {
        customOrderType('catering', 'كيترنج', OrderBehavior::COUNTER);

        $order = Order::factory()->create([
            'type' => 'catering',
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
        ]);

        livewire(ListOrders::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$order])
            ->assertSee('كيترنج');
    });
});
