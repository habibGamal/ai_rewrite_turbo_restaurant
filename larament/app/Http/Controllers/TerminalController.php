<?php

namespace App\Http\Controllers;

use App\Enums\OptionalFeature;
use App\Models\Terminal;
use App\Models\TerminalSession;
use App\Services\FeatureService;
use App\Services\ShiftLoggingService;
use App\Services\ShiftService;
use App\Terminals\TerminalDeviceService;
use App\Terminals\TerminalException;
use App\Terminals\TerminalSessionResolver;
use App\Terminals\TerminalSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cashier-side drawer screens (multiple terminals on): bind this device to a
 * drawer, open the drawer, take it over from another cashier, close it.
 */
class TerminalController extends Controller
{
    public function __construct(
        private readonly FeatureService $features,
        private readonly TerminalDeviceService $devices,
        private readonly TerminalSessionService $sessions,
        private readonly ShiftService $shifts,
        private readonly ShiftLoggingService $logging,
    ) {}

    /**
     * Resolved per call: the resolver is request-scoped and a controller
     * instance can outlive one request.
     */
    private function resolver(): TerminalSessionResolver
    {
        return app(TerminalSessionResolver::class);
    }

    public function device(Request $request): Response|RedirectResponse
    {
        $this->features->abortUnlessEnabled(OptionalFeature::MULTI_TERMINAL, 404);

        $current = $this->resolver()->terminal();

        return Inertia::render('Terminals/BindDevice', [
            'current' => $current?->only(['id', 'name']),
            'canBind' => $request->user()?->can('terminal.bind_device') ?? false,
            'terminals' => Terminal::query()->active()->orderBy('sort')->orderBy('id')
                ->withCount('devices')
                ->get(['id', 'name'])
                ->map(fn (Terminal $t): array => ['id' => $t->id, 'name' => $t->name, 'devices' => $t->devices_count]),
        ]);
    }

    public function bindDevice(Request $request): RedirectResponse
    {
        $this->features->abortUnlessEnabled(OptionalFeature::MULTI_TERMINAL, 404);

        $data = $request->validate([
            'terminal_id' => ['required', 'integer', 'exists:terminals,id'],
        ]);

        $terminal = Terminal::query()->active()->findOrFail($data['terminal_id']);
        $cookie = $this->devices->bind($request, $terminal, $request->user());

        $this->logging->logAction('ربط جهاز بدرج', ['terminal' => $terminal->name]);

        return redirect()->route('terminals.open')
            ->with('success', 'تم ربط الجهاز بـ «'.$terminal->name.'»')
            ->withCookie($cookie);
    }

    public function showOpen(Request $request): Response|RedirectResponse
    {
        $this->features->abortUnlessEnabled(OptionalFeature::MULTI_TERMINAL, 404);

        $terminal = $this->resolver()->terminal();
        if ($terminal === null) {
            return redirect()->route('terminals.device');
        }

        $session = $this->resolver()->current();
        if ($session !== null) {
            return $session->user_id === $request->user()?->getKey()
                ? redirect()->route('orders.index')
                : redirect()->route('terminals.handover');
        }

        $user = $request->user();
        $shift = $this->shifts->getCurrentShift();
        $canEndShift = $user?->can('shift.end') ?? false;
        $canCloseOthers = $user?->can('terminal.close_others') ?? false;

        return Inertia::render('Terminals/OpenDrawer', [
            'terminal' => $terminal->only(['id', 'name']),
            'hasShift' => $shift !== null,
            'canOpen' => $user?->can('terminal.open') ?? false,
            'canEndShift' => $shift !== null && $canEndShift,
            'canCloseOthers' => $canCloseOthers,
            // The shift's drawers, for whoever ends the shift or closes drawers.
            'sessions' => $shift !== null && ($canEndShift || $canCloseOthers)
                ? $shift->terminalSessions()->with(['terminal', 'user'])->orderBy('id')->get()
                    ->map(fn (TerminalSession $s): array => [
                        'id' => $s->id,
                        'terminal' => $s->terminal->name,
                        'cashier' => $s->user?->name,
                        'openedAt' => $s->opened_at->toIso8601String(),
                        'closedAt' => $s->closed_at?->toIso8601String(),
                        'openingCash' => (float) $s->opening_cash,
                        'expectedCash' => $canEndShift
                            ? ($s->isOpen() ? $this->sessions->expectedCash($s) : (float) $s->expected_cash)
                            : null,
                    ])->values()
                : [],
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $this->features->abortUnlessEnabled(OptionalFeature::MULTI_TERMINAL, 404);

        $data = $request->validate([
            'opening_cash' => ['required', 'numeric', 'min:0'],
        ]);

        $terminal = $this->resolver()->terminal();
        if ($terminal === null) {
            return redirect()->route('terminals.device');
        }

        $hadShift = $this->shifts->getCurrentShift() !== null;

        try {
            $session = $this->sessions->openDrawer($terminal, $request->user(), (float) $data['opening_cash']);
        } catch (TerminalException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        if (! $hadShift) {
            $this->logging->logShiftAction('start', [
                'id' => $session->shift_id,
                'start_cash' => $data['opening_cash'],
                'date' => now()->format('Y-m-d H:i:s'),
            ]);
        }

        $this->logging->logAction('فتح الدرج', [
            'terminal' => $terminal->name,
            'opening_cash' => $data['opening_cash'],
        ]);

        return redirect()->route('orders.index')->with('success', 'تم فتح «'.$terminal->name.'»');
    }

    public function showHandover(Request $request): Response|RedirectResponse
    {
        $this->features->abortUnlessEnabled(OptionalFeature::MULTI_TERMINAL, 404);

        $session = $this->resolver()->current();
        if ($session === null) {
            return redirect()->route('terminals.open');
        }

        if ($session->user_id === $request->user()?->getKey()) {
            return redirect()->route('orders.index');
        }

        return Inertia::render('Terminals/Handover', [
            'terminal' => $session->terminal->only(['id', 'name']),
            'holder' => $session->user === null ? null : ($session->user->name ?: $session->user->email),
            'openedAt' => $session->opened_at->toIso8601String(),
            'canOpen' => $request->user()?->can('terminal.open') ?? false,
        ]);
    }

    public function handover(Request $request): RedirectResponse
    {
        $this->features->abortUnlessEnabled(OptionalFeature::MULTI_TERMINAL, 404);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $session = $this->resolver()->current();
        if ($session === null) {
            return redirect()->route('terminals.open');
        }

        $from = $session->user?->name;

        try {
            $this->sessions->handover($session, $request->user(), $data['note'] ?? null);
        } catch (TerminalException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        $this->logging->logAction('تسليم الدرج', [
            'terminal' => $session->terminal->name,
            'from' => $from,
            'to' => $request->user()->name,
        ]);

        return redirect()->route('orders.index')->with('success', 'استلمت «'.$session->terminal->name.'»');
    }

    public function close(Request $request): RedirectResponse
    {
        $this->features->abortUnlessEnabled(OptionalFeature::MULTI_TERMINAL, 404);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        $session = $this->resolver()->current();
        if ($session === null || $session->user_id !== $request->user()->getKey()) {
            return back()->withErrors(['error' => 'الدرج ده مش معاك.']);
        }

        $this->sessions->close($session, $request->user(), $data['note'] ?? null);
        $this->logging->logAction('قفل الدرج', ['terminal' => $session->terminal->name]);

        return redirect()->route('terminals.open')->with('success', 'تم قفل «'.$session->terminal->name.'»');
    }

    public function closeOther(Request $request, TerminalSession $session): RedirectResponse
    {
        $this->features->abortUnlessEnabled(OptionalFeature::MULTI_TERMINAL, 404);

        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);

        if (! $session->isOpen()) {
            return back()->withErrors(['error' => 'الدرج ده اتقفل بالفعل.']);
        }

        $this->sessions->close($session, $request->user(), $data['note']);
        $this->logging->logAction('قفل درج نيابةً', [
            'terminal' => $session->terminal->name,
            'cashier' => $session->user?->name,
            'reason' => $data['note'],
        ]);

        return back()->with('success', 'تم قفل «'.$session->terminal->name.'»');
    }
}
