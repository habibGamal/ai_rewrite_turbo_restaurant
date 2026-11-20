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

    /** Wrong PINs allowed per user before that badge is locked for a while. */
    private const MAX_PIN_ATTEMPTS = 5;

    private const PIN_LOCK_SECONDS = 900;

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
                'badge' => 'محاولات كثيرة. انتظر '.RateLimiter::availableIn($key).' ثانية.',
            ]);
        }

        $user = $this->badges->find($data['badge']);

        if ($user === null || ! $user->can('user.badge_login') || ! $user->can('pos.access')) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages(['badge' => 'هذا البادج غير مفعل.']);
        }

        if ($this->badges->requiresPin($user)) {
            // PIN guesses are counted per user, so scanning another badge
            // successfully never resets them.
            $pinKey = 'badge-pin:'.$user->getKey();

            if (RateLimiter::tooManyAttempts($pinKey, self::MAX_PIN_ATTEMPTS)) {
                throw ValidationException::withMessages([
                    'pin' => 'تم إيقاف هذا البادج مؤقتاً بسبب تكرار الرقم السري الخاطئ. حاول بعد '.ceil(RateLimiter::availableIn($pinKey) / 60).' دقيقة.',
                ]);
            }

            if (($data['pin'] ?? null) === null) {
                throw ValidationException::withMessages(['pin' => 'أدخل الرقم السري (PIN) الخاص بك.']);
            }

            if (! $this->badges->checkPin($user, $data['pin'])) {
                RateLimiter::hit($key);
                RateLimiter::hit($pinKey, self::PIN_LOCK_SECONDS);

                throw ValidationException::withMessages(['pin' => 'الرقم السري غير صحيح.']);
            }

            RateLimiter::clear($pinKey);
        }

        return $user;
    }

    private function login(Request $request, User $user): void
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(BadgeService::SESSION_KEY, true);
    }
}
