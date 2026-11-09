<?php

use App\Enums\OrderBehavior;
use App\Enums\OrderStatus;
use App\Enums\SettingKey;
use App\Models\Order;
use App\Models\OrderTypeRow;
use App\Models\Shift;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function setCashierTabs(array $entries): void
{
    app(SettingsService::class)->set(SettingKey::CASHIER_TABS->value, $entries);
}

function customTabOrderType(string $code, string $label, OrderBehavior $behavior, bool $active = true): OrderTypeRow
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

describe('cashier tabs', function () {
    beforeEach(function () {
        $this->user = User::factory()->admin()->create();
        $this->shift = Shift::factory()->create([
            'user_id' => $this->user->id,
            'end_at' => null,
            'closed' => false,
        ]);
        actingAs($this->user);
    });

    it('returns the tabs in the configured order and drops hidden ones', function () {
        setCashierTabs([
            ['key' => 'type:takeaway', 'visible' => true],
            ['key' => 'type:dine_in', 'visible' => false],
            ['key' => 'type:delivery', 'visible' => true],
            ['key' => 'type:talabat', 'visible' => true],
            ['key' => 'type:companies', 'visible' => true],
            ['key' => 'type:web_delivery', 'visible' => true],
            ['key' => 'type:web_takeaway', 'visible' => true],
            ['key' => 'system:expenses', 'visible' => true],
            ['key' => 'system:cancelled', 'visible' => true],
            ['key' => 'system:completed', 'visible' => true],
        ]);

        $this->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->component('Orders/Index')
                    ->has('tabs', 9)
                    ->where('tabs.0.key', 'type:takeaway')
                    ->where('tabs.0.presentation.body', 'cards')
                    ->where('tabs.0.presentation.creation', 'immediate')
                    ->where('tabs.0.presentation.statuses.0', 'processing')
                    ->where('tabs.0.presentation.cardTitle', 'order_number')
                    ->where('tabs.0.display.label', 'تيك أواي')
                    ->where('tabs.6.key', 'system:expenses')
                    ->where('tabs.6.forcedVisible', false);
            });
    });

    it('shows a hidden tab with an open order in the current shift as forced visible', function () {
        Order::factory()->create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'type' => 'delivery',
            'status' => OrderStatus::PROCESSING,
        ]);

        setCashierTabs([
            ['key' => 'type:dine_in', 'visible' => true],
            ['key' => 'type:delivery', 'visible' => false],
            ['key' => 'type:takeaway', 'visible' => true],
            ['key' => 'type:talabat', 'visible' => true],
            ['key' => 'type:companies', 'visible' => false],
            ['key' => 'type:web_delivery', 'visible' => true],
            ['key' => 'type:web_takeaway', 'visible' => true],
            ['key' => 'system:expenses', 'visible' => true],
            ['key' => 'system:cancelled', 'visible' => true],
            ['key' => 'system:completed', 'visible' => true],
        ]);

        $this->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->component('Orders/Index')
                    ->has('tabs', 9)
                    ->where('tabs.1.key', 'type:delivery')
                    ->where('tabs.1.visible', false)
                    ->where('tabs.1.forcedVisible', true);
            });
    });

    it('appends a custom active counter type with an immediate presentation', function () {
        customTabOrderType('catering', 'كيترنج', OrderBehavior::COUNTER);

        $this->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->component('Orders/Index')
                    ->has('tabs', 12)
                    ->where('tabs.11.key', 'type:catering')
                    ->where('tabs.11.code', 'catering')
                    ->where('tabs.11.visible', true)
                    ->where('tabs.11.presentation.creation', 'immediate')
                    ->where('tabs.11.display.label', 'كيترنج');
            });
    });

    it('adds a display payload to every order in the orders prop', function () {
        Order::factory()->create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'type' => 'dine_in',
            'status' => OrderStatus::PROCESSING,
        ]);

        $this->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->component('Orders/Index')
                    ->has('orders', 1)
                    ->where('orders.0.type', 'dine_in')
                    ->where('orders.0.display.code', 'dine_in')
                    ->where('orders.0.display.label', 'صالة')
                    ->where('orders.0.display.showTable', true)
                    ->where('orders.0.display.showDriver', false);
            });
    });

    it('returns change type options for visible cashier creatable types only', function () {
        customTabOrderType('banquet', 'مأدبة', OrderBehavior::COUNTER, active: false);

        setCashierTabs([
            ['key' => 'type:dine_in', 'visible' => true],
            ['key' => 'type:takeaway', 'visible' => true],
            ['key' => 'type:delivery', 'visible' => true],
            ['key' => 'type:talabat', 'visible' => false],
            ['key' => 'type:companies', 'visible' => false],
            ['key' => 'type:web_delivery', 'visible' => true],
            ['key' => 'type:web_takeaway', 'visible' => true],
            ['key' => 'system:receivables', 'visible' => false],
            ['key' => 'system:expenses', 'visible' => true],
            ['key' => 'system:cancelled', 'visible' => true],
            ['key' => 'system:completed', 'visible' => true],
        ]);

        $order = Order::factory()->create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'type' => 'takeaway',
            'status' => OrderStatus::PROCESSING,
        ]);

        $this->get(route('orders.manage', $order))
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->component('Orders/ManageOrder')
                    ->has('orderDisplay')
                    ->where('orderDisplay.code', 'takeaway')
                    ->where('orderDisplay.showCustomer', true)
                    ->has('changeTypeOptions', 3)
                    ->where('changeTypeOptions.0.code', 'dine_in')
                    ->where('changeTypeOptions.0.showTable', true)
                    ->where('changeTypeOptions.1.code', 'takeaway')
                    ->where('changeTypeOptions.1.showTable', false)
                    ->where('changeTypeOptions.2.code', 'delivery');
            });
    });

    it('rejects changing an order to a hidden type and allows a visible one', function () {
        $entries = json_decode((string) SettingKey::CASHIER_TABS->defaultValue(), true);
        foreach ($entries as $index => $entry) {
            if ($entry['key'] === 'type:companies') {
                $entries[$index]['visible'] = false;
            }
        }
        setCashierTabs($entries);

        $order = Order::factory()->create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'type' => 'takeaway',
            'status' => OrderStatus::PROCESSING,
        ]);

        $this->post(route('orders.updateType', $order), [
            'type' => 'companies',
        ])->assertSessionHasErrors('error');

        expect($order->fresh()->type)->toBe('takeaway');

        $this->post(route('orders.updateType', $order), [
            'type' => 'delivery',
        ])->assertRedirect();

        expect($order->fresh()->type)->toBe('delivery');
    });
});
