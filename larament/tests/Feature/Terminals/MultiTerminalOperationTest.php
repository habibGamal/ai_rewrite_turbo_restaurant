<?php

use App\Enums\SettingKey;
use App\Filament\Pages\VendorConsole;
use App\Filament\Resources\Terminals\Pages\CreateTerminal;
use App\Models\HeldSale;
use App\Models\Order;
use App\Models\Shift;
use App\Models\Terminal;
use App\Models\TerminalDevice;
use App\Models\TerminalSession;
use App\Models\TerminalSessionHandover;
use App\Models\User;
use App\Services\FeatureService;
use App\Services\PrintService;
use App\Services\SettingsService;
use App\Services\ShiftService;
use App\Terminals\MultiTerminalDisableBlocker;
use App\Terminals\TerminalDeviceService;
use App\Terminals\TerminalSessionResolver;
use App\Terminals\TerminalSessionService;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

function enableDrawers(int $max = 3): void
{
    app(SettingsService::class)->set(SettingKey::FEATURE_MULTI_TERMINAL_ENABLED->value, true);
    app(SettingsService::class)->set(SettingKey::MAX_TERMINALS->value, (string) $max);
}

/**
 * Bind a fresh drawer and return the plain cookie token for it.
 */
function bindDrawer(Terminal $terminal, User $by): string
{
    $request = request();
    $cookie = app(TerminalDeviceService::class)->bind($request, $terminal, $by);

    return (string) $cookie->getValue();
}

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
    openInventoryDay();
});

describe('while the feature is off', function () {
    it('keeps the old start-shift flow and hides drawer screens', function () {
        $this->get(route('shifts.start'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Shifts/StartShift')->where('terminal', null));

        $this->get(route('terminals.open'))->assertNotFound();
        $this->get(route('terminals.device'))->assertNotFound();
    });

    it('ends the shift and closes the hidden drawer as before', function () {
        $shift = app(ShiftService::class)->startShift(100);

        $this->post(route('shifts.end'), ['real_end_cash' => 0])->assertRedirect(route('shifts.start'));

        expect($shift->fresh()->closed)->toBeTrue()
            ->and($shift->terminalSessions()->open()->count())->toBe(0);
    });
});

describe('with multiple drawers on', function () {
    beforeEach(function () {
        enableDrawers();
        $this->front = Terminal::query()->create(['name' => 'كاشير ١', 'receipt_printer' => '192.168.1.50']);
        $this->back = Terminal::query()->create(['name' => 'كاشير ٢']);
    });

    it('sends an unbound device to the binding screen', function () {
        $this->get(route('orders.index'))->assertRedirect(route('terminals.device'));
        $this->get(route('shifts.start'))->assertRedirect(route('terminals.open'));

        $this->get(route('terminals.device'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Terminals/BindDevice')->where('canBind', true));
    });

    it('binds the device with a cookie and remembers only the hash', function () {
        $response = $this->post(route('terminals.device.bind'), ['terminal_id' => $this->front->id]);

        $response->assertRedirect(route('terminals.open'))->assertCookie(TerminalDeviceService::COOKIE);

        $device = TerminalDevice::query()->sole();
        $token = $response->getCookie(TerminalDeviceService::COOKIE, false)->getValue();

        expect($device->terminal_id)->toBe($this->front->id)
            ->and($device->token_hash)->not->toBe($token)
            ->and($device->bound_by)->toBe($this->admin->id);
    });

    it('does not let a cashier bind a device', function () {
        actingAs(User::factory()->cashier()->create());

        $this->post(route('terminals.device.bind'), ['terminal_id' => $this->front->id])->assertForbidden();
        expect(TerminalDevice::query()->count())->toBe(0);
    });

    it('opens the shift with the first drawer and adds the second drawer cash', function () {
        $frontToken = bindDrawer($this->front, $this->admin);
        $backToken = bindDrawer($this->back, $this->admin);

        $this->withCookie(TerminalDeviceService::COOKIE, $frontToken)
            ->get(route('orders.index'))->assertRedirect(route('terminals.open'));

        $this->withCookie(TerminalDeviceService::COOKIE, $frontToken)
            ->post(route('terminals.open.store'), ['opening_cash' => 300])
            ->assertRedirect(route('orders.index'));

        $shift = Shift::query()->whereNull('end_at')->sole();
        expect((float) $shift->start_cash)->toBe(300.0);

        $cashier = User::factory()->cashier()->create();
        actingAs($cashier);
        app()->forgetScopedInstances();

        $this->withCookie(TerminalDeviceService::COOKIE, $backToken)
            ->post(route('terminals.open.store'), ['opening_cash' => 200])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('orders.index'));

        $shift->refresh();
        $sessions = $shift->terminalSessions()->orderBy('id')->get();

        expect(Shift::query()->count())->toBe(1)
            ->and((float) $shift->start_cash)->toBe(500.0)
            ->and($sessions)->toHaveCount(2)
            ->and($sessions[0]->terminal_id)->toBe($this->front->id)
            ->and($sessions[1]->terminal_id)->toBe($this->back->id)
            ->and($sessions[1]->user_id)->toBe($cashier->id);
    });

    it('stamps orders with the drawer of the device and shares it with the page', function () {
        $token = bindDrawer($this->front, $this->admin);
        $this->withCookie(TerminalDeviceService::COOKIE, $token)
            ->post(route('terminals.open.store'), ['opening_cash' => 0]);
        app()->forgetScopedInstances();

        $this->withCookie(TerminalDeviceService::COOKIE, $token)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('terminal.id', $this->front->id)
                ->where('terminal.name', 'كاشير ١'));

        $session = TerminalSession::query()->open()->sole();
        $resolver = app(TerminalSessionResolver::class);
        $resolver->setTerminal($this->front);
        $resolver->setCurrent($session);

        $order = Order::factory()->create(['shift_id' => $session->shift_id]);

        expect($order->terminal_id)->toBe($this->front->id);
    });

    it('lets one cashier hold only one drawer at a time', function () {
        $frontToken = bindDrawer($this->front, $this->admin);
        $backToken = bindDrawer($this->back, $this->admin);

        $this->withCookie(TerminalDeviceService::COOKIE, $frontToken)
            ->post(route('terminals.open.store'), ['opening_cash' => 0]);
        app()->forgetScopedInstances();

        $this->withCookie(TerminalDeviceService::COOKIE, $backToken)
            ->post(route('terminals.open.store'), ['opening_cash' => 0])
            ->assertSessionHasErrors('error');

        expect(TerminalSession::query()->open()->count())->toBe(1);
    });

    it('hands the drawer over on the same device: same session, note recorded', function () {
        $token = bindDrawer($this->front, $this->admin);
        $this->withCookie(TerminalDeviceService::COOKIE, $token)
            ->post(route('terminals.open.store'), ['opening_cash' => 150]);
        $session = TerminalSession::query()->open()->sole();

        $next = User::factory()->cashier()->create();
        actingAs($next);
        app()->forgetScopedInstances();

        $this->withCookie(TerminalDeviceService::COOKIE, $token)
            ->get(route('orders.index'))->assertRedirect(route('terminals.handover'));

        app()->forgetScopedInstances();
        $this->withCookie(TerminalDeviceService::COOKIE, $token)
            ->post(route('terminals.handover.store'), ['note' => 'تسليم نص الوردية'])
            ->assertRedirect(route('orders.index'));

        $session->refresh();
        $handover = TerminalSessionHandover::query()->sole();

        expect(TerminalSession::query()->count())->toBe(1)
            ->and($session->isOpen())->toBeTrue()
            ->and($session->user_id)->toBe($next->id)
            ->and((float) $session->opening_cash)->toBe(150.0)
            ->and($handover->from_user_id)->toBe($this->admin->id)
            ->and($handover->to_user_id)->toBe($next->id)
            ->and($handover->note)->toBe('تسليم نص الوردية');
    });

    it('closes the drawer, then ends the shift only after every drawer is closed', function () {
        $frontToken = bindDrawer($this->front, $this->admin);
        $backToken = bindDrawer($this->back, $this->admin);

        $this->withCookie(TerminalDeviceService::COOKIE, $frontToken)
            ->post(route('terminals.open.store'), ['opening_cash' => 100]);

        $cashier = User::factory()->cashier()->create();
        actingAs($cashier);
        app()->forgetScopedInstances();
        $this->withCookie(TerminalDeviceService::COOKIE, $backToken)
            ->post(route('terminals.open.store'), ['opening_cash' => 50]);

        actingAs($this->admin);
        app()->forgetScopedInstances();
        $this->withCookie(TerminalDeviceService::COOKIE, $frontToken)
            ->post(route('terminals.close'), ['note' => 'آخر اليوم'])
            ->assertRedirect(route('terminals.open'));

        $shift = Shift::query()->sole();
        $front = $shift->terminalSessions()->where('terminal_id', $this->front->id)->sole();
        expect($front->isOpen())->toBeFalse()
            ->and((float) $front->expected_cash)->toBe(100.0)
            ->and($front->close_note)->toBe('آخر اليوم');

        app()->forgetScopedInstances();
        $this->withCookie(TerminalDeviceService::COOKIE, $frontToken)
            ->get(route('terminals.open'))
            ->assertInertia(fn ($page) => $page->component('Terminals/OpenDrawer')
                ->where('canEndShift', true)
                ->has('sessions', 2));

        app()->forgetScopedInstances();
        $this->post(route('shifts.end'), ['real_end_cash' => 0]);
        expect($shift->fresh()->closed)->toBeFalse();

        $back = $shift->terminalSessions()->where('terminal_id', $this->back->id)->sole();
        app()->forgetScopedInstances();
        $this->post(route('terminals.sessions.close', $back), [])->assertSessionHasErrors('note');
        $this->post(route('terminals.sessions.close', $back), ['note' => 'الكاشير مشي'])->assertSessionHasNoErrors();

        expect($back->fresh()->closed_by)->toBe($this->admin->id)
            ->and($back->fresh()->close_note)->toBe('الكاشير مشي');

        app()->forgetScopedInstances();
        $this->post(route('shifts.end'), ['real_end_cash' => 0]);
        expect($shift->fresh()->closed)->toBeTrue();
    });

    it('does not let a cashier close someone else drawer', function () {
        $token = bindDrawer($this->front, $this->admin);
        $this->withCookie(TerminalDeviceService::COOKIE, $token)
            ->post(route('terminals.open.store'), ['opening_cash' => 0]);
        $session = TerminalSession::query()->open()->sole();

        actingAs(User::factory()->cashier()->create());
        $this->post(route('terminals.sessions.close', $session), ['note' => 'x'])->assertForbidden();

        expect($session->fresh()->isOpen())->toBeTrue();
    });

    it('prints receipts to the drawer printer, falling back to the settings printer', function () {
        app(SettingsService::class)->set(SettingKey::CASHIER_PRINTER_IP->value, '10.0.0.9');
        $resolver = app(TerminalSessionResolver::class);
        $print = app(PrintService::class);

        $resolver->setTerminal($this->front);
        expect($print->cashierPrinter())->toBe('192.168.1.50');

        $resolver->setTerminal($this->back);
        expect($print->cashierPrinter())->toBe('10.0.0.9');
    });

    it('keeps held sales per drawer', function () {
        $shift = app(ShiftService::class)->startShift(0, $this->admin, $this->front);
        $front = $shift->terminalSessions()->sole();
        $back = app(TerminalSessionService::class)
            ->openDrawer($this->back, User::factory()->cashier()->create(), 0);

        HeldSale::query()->create(['shift_id' => $shift->id, 'user_id' => $this->admin->id, 'terminal_session_id' => $front->id, 'order_type' => 'takeaway', 'label' => 'أ', 'lines' => [], 'total' => 0]);
        HeldSale::query()->create(['shift_id' => $shift->id, 'user_id' => $this->admin->id, 'terminal_session_id' => $back->id, 'order_type' => 'takeaway', 'label' => 'ب', 'lines' => [], 'total' => 0]);

        $token = bindDrawer($this->front, $this->admin);
        app()->forgetScopedInstances();

        $this->withCookie(TerminalDeviceService::COOKIE, $token)
            ->withCredentials()
            ->getJson(route('pos.held-sales.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('refuses an active drawer past the vendor limit', function () {
        // The default drawer counts too: it is the shop's first drawer.
        Terminal::default();
        expect(Terminal::query()->active()->count())->toBe(3);

        Livewire::test(CreateTerminal::class)
            ->fillForm(['name' => 'كاشير ٣', 'is_active' => false, 'sort' => 0])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateTerminal::class)
            ->fillForm(['name' => 'كاشير ٤', 'is_active' => true, 'sort' => 0])
            ->call('create')
            ->assertHasFormErrors(['is_active']);

        expect(Terminal::query()->active()->count())->toBe(3);
    });

    it('blocks switching the feature off while a drawer is open', function () {
        app(ShiftService::class)->startShift(0, $this->admin, $this->front);

        expect((new MultiTerminalDisableBlocker)())->not->toBeEmpty();

        expect(fn () => app(SettingsService::class)->set(SettingKey::FEATURE_MULTI_TERMINAL_ENABLED->value, false))
            ->toThrow(ValidationException::class);

        expect(app(FeatureService::class)->multiTerminalEnabled())->toBeTrue();
    });
});

it('hides the drawer permissions while the feature is off', function () {
    $cashier = User::factory()->cashier()->create();

    expect($cashier->can('terminal.open'))->toBeFalse();

    enableDrawers();

    expect($cashier->fresh()->can('terminal.open'))->toBeTrue();
});

it('lets the vendor switch drawers on and set the drawer limit', function () {
    actingAs(User::factory()->vendor()->create());

    Livewire::test(VendorConsole::class)
        ->assertFormFieldIsHidden('max_terminals', 'form')
        ->fillForm([
            'features.'.SettingKey::FEATURE_MULTI_TERMINAL_ENABLED->value => true,
            'max_terminals' => 4,
        ])
        ->assertFormFieldIsVisible('max_terminals', 'form')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(FeatureService::class)->multiTerminalEnabled())->toBeTrue()
        ->and((int) setting(SettingKey::MAX_TERMINALS))->toBe(4);
});
