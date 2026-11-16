<?php

namespace App\Http\Controllers\Auth;

use App\Auth\BadgeService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ShiftLoggingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Badge (barcode card) quick login to the POS, and swapping the cashier on a
 * busy device by scanning another badge. Badge sessions are POS-only.
 */
class BadgeLoginController extends Controller
{
    private const MAX_ATTEMPTS = 10;

    public function __construct(private readonly BadgeService $badges) {}

    public function create(): Response
    {
        return Inertia::render('Auth/BadgeLogin');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->resolve($request);

        $this->login($request, $user);

        return $user->hasRole('kitchen') && ! $user->can('pos.access')
            ? redirect()->route('kitchen.index')
            : redirect()->route('orders.index');
    }

    /**
     * Another cashier scans their badge on a device someone is signed in
     * on. With drawers on, the POS then asks them to take the drawer over.
     */
    public function switch(Request $request): RedirectResponse
    {
        $user = $this->resolve($request);
        $from = $request->user();

        if ($from instanceof User && $from->is($user)) {
            return redirect()->route('orders.index');
        }

        $this->login($request, $user);

        app(ShiftLoggingService::class)->logAction('تبديل الكاشير بالبادج', [
            'from' => $from?->name,
            'to' => $user->name,
        ]);

        return redirect()->route('orders.index')->with('success', 'أهلاً '.$user->name);
    }

    /**
     * @throws ValidationException
     */
    private function resolve(Request $request): User
    {
        $data = $request->validate([
            'badge' => ['required', 'string', 'max:64'],
            'pin' => ['nullable', 'string', 'digits:4'],
        ]);

        $key = 'badge-login:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'badge' => 'محاولات كتير. استنى '.RateLimiter::availableIn($key).' ثانية.',
            ]);
        }

        $user = $this->badges->find($data['badge']);

        if ($user === null || ! $user->can('user.badge_login') || ! $user->can('pos.access')) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages(['badge' => 'البادج ده مش شغال.']);
        }

        if ($this->badges->requiresPin($user)) {
            if (($data['pin'] ?? null) === null) {
                throw ValidationException::withMessages(['pin' => 'اكتب الرقم السري (PIN) بتاعك.']);
            }

            if (! $this->badges->checkPin($user, $data['pin'])) {
                RateLimiter::hit($key);

                throw ValidationException::withMessages(['pin' => 'الرقم السري غلط.']);
            }
        }

        RateLimiter::clear($key);

        return $user;
    }

    private function login(Request $request, User $user): void
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(BadgeService::SESSION_KEY, true);
    }
}
