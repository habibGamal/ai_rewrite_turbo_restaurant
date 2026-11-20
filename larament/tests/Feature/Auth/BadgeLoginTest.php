<?php

use App\Auth\BadgeService;
use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    RateLimiter::clear('badge-login:127.0.0.1');
    // The base test case signs a user in; badge login is for guests.
    auth()->guard('web')->logout();
});

it('shows the badge login page to guests', function () {
    $this->get(route('badge.login'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/BadgeLogin'));
});

it('keeps only a hash of the badge and replaces it on reprint', function () {
    $cashier = User::factory()->cashier()->create();
    $badges = app(BadgeService::class);

    $first = $badges->issue($cashier);
    $second = $badges->issue($cashier);

    expect($first)->toMatch('/^\d{16}$/')
        ->and($cashier->fresh()->badge_hash)->toBe(hash('sha256', $second))
        ->and($badges->find($first))->toBeNull()
        ->and($badges->find($second)?->is($cashier))->toBeTrue();
});

it('logs a cashier into the POS with the badge alone', function () {
    $cashier = User::factory()->cashier()->create();
    $code = app(BadgeService::class)->issue($cashier);

    $this->post(route('badge.login.store'), ['badge' => $code])
        ->assertRedirect(route('orders.index'));

    $this->assertAuthenticatedAs($cashier);
    expect(session(BadgeService::SESSION_KEY))->toBeTrue();
});

it('refuses an unknown badge', function () {
    $this->post(route('badge.login.store'), ['badge' => '1234567890123456'])
        ->assertSessionHasErrors('badge');

    $this->assertGuest();
});

it('refuses a user without the badge login permission', function () {
    $kitchen = User::factory()->kitchen()->create();
    $code = app(BadgeService::class)->issue($kitchen);

    $this->post(route('badge.login.store'), ['badge' => $code])->assertSessionHasErrors('badge');
    $this->assertGuest();
});

it('asks a manager for the PIN and checks it', function () {
    $admin = User::factory()->admin()->create(['badge_pin' => '4321']);
    $code = app(BadgeService::class)->issue($admin);

    $this->post(route('badge.login.store'), ['badge' => $code])->assertSessionHasErrors('pin');
    $this->assertGuest();

    $this->post(route('badge.login.store'), ['badge' => $code, 'pin' => '0000'])->assertSessionHasErrors('pin');
    $this->assertGuest();

    $this->post(route('badge.login.store'), ['badge' => $code, 'pin' => '4321'])
        ->assertRedirect(route('orders.index'));
    $this->assertAuthenticatedAs($admin);
});

it('does not open the admin panel from a badge login', function () {
    $admin = User::factory()->admin()->create(['badge_pin' => '4321']);
    $code = app(BadgeService::class)->issue($admin);

    $this->post(route('badge.login.store'), ['badge' => $code, 'pin' => '4321']);

    $this->get('/admin')->assertRedirect(route('orders.index'));
});

it('opens the admin panel after a password login', function () {
    $admin = User::factory()->admin()->create(['password' => 'secret-pass', 'badge_pin' => '4321']);
    $code = app(BadgeService::class)->issue($admin);

    $this->post(route('badge.login.store'), ['badge' => $code, 'pin' => '4321']);
    expect(session(BadgeService::SESSION_KEY))->toBeTrue();

    auth()->guard('web')->logout();
    Livewire::test(Login::class)
        ->fillForm(['email' => $admin->email, 'password' => 'secret-pass'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(session(BadgeService::SESSION_KEY))->toBeNull();
    $this->get('/admin')->assertRedirect('/admin/current-shift-report');
});

it('swaps the signed-in cashier when another badge is scanned', function () {
    $first = User::factory()->cashier()->create();
    $second = User::factory()->cashier()->create();
    $code = app(BadgeService::class)->issue($second);

    actingAs($first);

    $this->post(route('badge.switch'), ['badge' => $code])
        ->assertRedirect(route('orders.index'));

    $this->assertAuthenticatedAs($second);
});

it('locks the badge scan after too many wrong badges', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->post(route('badge.login.store'), ['badge' => 'x'.$i]);
    }

    $cashier = User::factory()->cashier()->create();
    $code = app(BadgeService::class)->issue($cashier);

    $this->post(route('badge.login.store'), ['badge' => $code])->assertSessionHasErrors('badge');
    $this->assertGuest();
});

it('prints the badge card once from the users screen', function () {
    $admin = User::factory()->admin()->create();
    $cashier = User::factory()->cashier()->create();
    actingAs($admin);

    Livewire::test(ListUsers::class)
        ->callTableAction('printBadge', $cashier)
        ->assertRedirect(route('users.badge.print', $cashier));

    $cashier->refresh();
    expect($cashier->badge_hash)->not->toBeNull();

    $response = $this->get(route('users.badge.print', $cashier))->assertOk();
    $code = $response->viewData('code');
    expect(hash('sha256', $code))->toBe($cashier->badge_hash);
    $response->assertSee($cashier->name)->assertSee('<svg', false);

    $this->get(route('users.badge.print', $cashier))->assertOk()->assertViewHas('code', null);
});

it('hashes the badge PIN set from the user form', function () {
    $user = User::factory()->admin()->create(['badge_pin' => '1234']);

    expect($user->getAttributes()['badge_pin'])->not->toBe('1234')
        ->and(app(BadgeService::class)->checkPin($user, '1234'))->toBeTrue();
});

it('locks a manager badge after repeated wrong PINs, even if another badge logs in between', function () {
    $admin = User::factory()->admin()->create(['badge_pin' => '4321']);
    $code = app(BadgeService::class)->issue($admin);
    $cashier = User::factory()->cashier()->create();
    $cashierCode = app(BadgeService::class)->issue($cashier);

    foreach (['0000', '0001', '0002'] as $pin) {
        $this->post(route('badge.login.store'), ['badge' => $code, 'pin' => $pin])->assertSessionHasErrors('pin');
    }

    // A good scan of another badge must not reset the manager's PIN counter.
    $this->post(route('badge.login.store'), ['badge' => $cashierCode])->assertRedirect(route('orders.index'));
    auth()->guard('web')->logout();

    foreach (['0003', '0004'] as $pin) {
        $this->post(route('badge.login.store'), ['badge' => $code, 'pin' => $pin])->assertSessionHasErrors('pin');
    }

    // Locked now: even the right PIN is refused.
    $this->post(route('badge.login.store'), ['badge' => $code, 'pin' => '4321'])->assertSessionHasErrors('pin');
    $this->assertGuest();
});

it('keeps badge sessions away from the profile page', function () {
    $admin = User::factory()->admin()->create(['badge_pin' => '4321', 'email' => 'boss@example.com']);
    $code = app(BadgeService::class)->issue($admin);

    $this->post(route('badge.login.store'), ['badge' => $code, 'pin' => '4321']);

    $this->get('/profile')->assertRedirect(route('orders.index'));
    $this->patch('/profile', ['name' => 'x', 'email' => 'attacker@example.com'])->assertRedirect(route('orders.index'));

    expect($admin->fresh()->email)->toBe('boss@example.com');
});

it('has no public broadcast test routes', function () {
    $this->get('/test-broadcast')->assertNotFound();
    $this->get('/test-simple')->assertNotFound();
});
