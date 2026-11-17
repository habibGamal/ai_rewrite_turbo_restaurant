<?php

use App\Enums\OrderStatus;
use App\Enums\SettingKey;
use App\Models\Order;
use App\Models\Shift;
use App\Models\Terminal;
use App\Models\User;
use App\Services\SettingsService;
use App\Terminals\TerminalDeviceService;
use App\Terminals\TerminalSeparation;
use App\Terminals\TerminalSessionResolver;

use function Pest\Laravel\actingAs;

function separatedDrawers(bool $separation = true): void
{
    app(SettingsService::class)->set(SettingKey::FEATURE_MULTI_TERMINAL_ENABLED->value, true);
    app(SettingsService::class)->set(SettingKey::MAX_TERMINALS->value, '3');
    app(SettingsService::class)->set(SettingKey::FEATURE_TERMINAL_SEPARATION_ENABLED->value, $separation);
}

function drawerToken(Terminal $terminal, User $by): string
{
    return (string) app(TerminalDeviceService::class)->bind(request(), $terminal, $by)->getValue();
}

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
    openInventoryDay();

    $this->one = Terminal::query()->create(['name' => 'منفذ ١']);
    $this->two = Terminal::query()->create(['name' => 'منفذ ٢']);
});

/**
 * Open the shift on both drawers and return [tokenOne, tokenTwo, shift].
 * Drawer one is held by $admin and drawer two by test()->cashierTwo; call
 * asTwo()/asOne() before a request from that drawer.
 *
 * @return array{0: string, 1: string, 2: Shift}
 */
function openBothDrawers(Terminal $one, Terminal $two, User $admin): array
{
    $tokenOne = drawerToken($one, $admin);
    $tokenTwo = drawerToken($two, $admin);

    test()->withCookie(TerminalDeviceService::COOKIE, $tokenOne)->post(route('terminals.open.store'), ['opening_cash' => 0]);
    app()->forgetScopedInstances();

    $other = User::factory()->admin()->create();
    test()->userTwo = $other;
    actingAs($other);
    test()->withCookie(TerminalDeviceService::COOKIE, $tokenTwo)->post(route('terminals.open.store'), ['opening_cash' => 0]);
    app()->forgetScopedInstances();
    actingAs($admin);

    return [$tokenOne, $tokenTwo, Shift::query()->whereNull('end_at')->sole()];
}

it('refuses another sales point order and hides it from the desk when separated', function () {
    separatedDrawers();
    [$tokenOne, $tokenTwo, $shift] = openBothDrawers($this->one, $this->two, $this->admin);

    $order = Order::factory()->create(['shift_id' => $shift->id, 'terminal_id' => $this->one->id, 'status' => OrderStatus::PROCESSING]);

    actingAs($this->userTwo);
    $this->withCookie(TerminalDeviceService::COOKIE, $tokenTwo)
        ->get(route('orders.manage', $order))
        ->assertForbidden();
    app()->forgetScopedInstances();

    actingAs($this->userTwo);
    $this->withCookie(TerminalDeviceService::COOKIE, $tokenTwo)
        ->post(route('orders.cancel', $order))
        ->assertForbidden();
    app()->forgetScopedInstances();

    actingAs($this->userTwo);
    $this->withCookie(TerminalDeviceService::COOKIE, $tokenTwo)
        ->get(route('orders.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders', fn ($orders) => collect($orders)->doesntContain('id', $order->id)));
    app()->forgetScopedInstances();

    actingAs($this->admin);
    $this->withCookie(TerminalDeviceService::COOKIE, $tokenOne)
        ->get(route('orders.manage', $order))
        ->assertOk();
    app()->forgetScopedInstances();

    actingAs($this->admin);
    $this->withCookie(TerminalDeviceService::COOKIE, $tokenOne)
        ->get(route('orders.index'))
        ->assertInertia(fn ($page) => $page->where('orders', fn ($orders) => collect($orders)->contains('id', $order->id)));
});

it('lets any sales point handle any order while not separated', function () {
    separatedDrawers(separation: false);
    [, $tokenTwo, $shift] = openBothDrawers($this->one, $this->two, $this->admin);

    $order = Order::factory()->create(['shift_id' => $shift->id, 'terminal_id' => $this->one->id, 'status' => OrderStatus::PROCESSING]);

    actingAs($this->userTwo);
    $this->withCookie(TerminalDeviceService::COOKIE, $tokenTwo)
        ->get(route('orders.manage', $order))
        ->assertOk();
});

it('does not limit requests with no bound device, like the admin panel', function () {
    separatedDrawers();
    $order = Order::factory()->create(['terminal_id' => $this->one->id]);

    app(TerminalSessionResolver::class)->setTerminal(null);
    expect(app(TerminalSeparation::class)->canAct($order))->toBeTrue();

    app(TerminalSessionResolver::class)->setTerminal($this->two);
    expect(app(TerminalSeparation::class)->canAct($order))->toBeFalse()
        ->and(app(TerminalSeparation::class)->denialMessage($order))->toContain('منفذ ١');
});

it('refuses a quick return of another sales point order', function () {
    separatedDrawers();
    app(SettingsService::class)->set(SettingKey::FEATURE_RETURNS_ENABLED->value, true);
    [, $tokenTwo, $shift] = openBothDrawers($this->one, $this->two, $this->admin);

    $order = Order::factory()->create(['shift_id' => $shift->id, 'terminal_id' => $this->one->id, 'status' => OrderStatus::COMPLETED]);

    actingAs($this->userTwo);
    $this->withCookie(TerminalDeviceService::COOKIE, $tokenTwo)
        ->withCredentials()
        ->getJson(route('pos.instant-returns.lookup', ['number' => '#'.$order->id]))
        ->assertForbidden()
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'منفذ ١'));
});
