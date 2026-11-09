<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\SettingKey;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('ShiftController Feature Tests', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();

        $this->actingAs($this->user);
    });

    it('shows the start shift page when no shift is active', function () {
        $response = $this->get(route('shifts.start'));

        $response->assertOk()
            ->assertInertia(function ($page) {
                $page->component('Shifts/StartShift');
            });
    });

    it('starts a shift and redirects to the orders index', function () {
        openInventoryDay();

        $response = $this->post(route('shifts.store'), [
            'start_cash' => 500.00,
        ]);

        $response->assertRedirect(route('orders.index'))
            ->assertSessionHas('success', 'تم بدء الوردية بنجاح');

        $this->assertDatabaseHas('shifts', [
            'user_id' => $this->user->id,
            'start_cash' => 500.00,
            'end_at' => null,
            'closed' => false,
        ]);
    });

    it('ends the active shift and redirects to the start page', function () {
        $shift = Shift::factory()->active()->create([
            'user_id' => $this->user->id,
        ]);

        $response = $this->post(route('shifts.end'), [
            'real_end_cash' => 1200.00,
        ]);

        $response->assertRedirect(route('shifts.start'))
            ->assertSessionHas('success', 'تم إنهاء الشيفت بنجاح');

        $shift->refresh();

        expect($shift->real_cash)->toBe('1200.00')
            ->and($shift->closed)->toBeTrue()
            ->and($shift->end_at)->not->toBeNull();
    });

    it('logs the transferred web order count when starting a shift', function () {
        openInventoryDay();

        app(SettingsService::class)->set(SettingKey::ALLOW_WEB_ORDERS_SHIFT_TRANSFER->value, 'true');

        $closedShift = Shift::factory()->closed()->create([
            'end_at' => now()->subHour(),
        ]);

        Order::factory()->create([
            'shift_id' => $closedShift->id,
            'type' => OrderType::WEB_DELIVERY,
            'status' => OrderStatus::PROCESSING,
            'order_number' => 1,
        ]);

        Order::factory()->create([
            'shift_id' => $closedShift->id,
            'type' => OrderType::WEB_TAKEAWAY,
            'status' => OrderStatus::PENDING,
            'order_number' => 2,
        ]);

        Order::factory()->create([
            'shift_id' => $closedShift->id,
            'type' => OrderType::TAKEAWAY,
            'status' => OrderStatus::PROCESSING,
            'order_number' => 3,
        ]);

        $response = $this->post(route('shifts.store'), [
            'start_cash' => 500.00,
        ]);

        $response->assertRedirect(route('orders.index'))
            ->assertSessionHas('success', 'تم بدء الوردية بنجاح');

        $shift = Shift::query()->whereNull('end_at')->where('closed', false)->first();
        $logPath = storage_path('logs/shifts/shift_'.$shift->id.'_'.$shift->created_at->format('Y-m-d').'.log');

        expect(is_file($logPath))->toBeTrue();

        $contents = file_get_contents($logPath);

        expect($contents)->toContain('بدء وردية جديدة')
            ->and($contents)->toContain('"transferred_web_orders":2');
    });
});
