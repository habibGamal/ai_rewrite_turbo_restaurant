<?php

namespace App\Http\Middleware;

use App\Models\Shift;
use App\Services\FeatureService;
use App\Terminals\TerminalSessionResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ShiftMiddleware
{
    public function __construct(
        private readonly FeatureService $features,
        private readonly TerminalSessionResolver $resolver,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request):Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->features->multiTerminalEnabled()) {
            return $this->handleDrawer($request, $next);
        }

        $currentShift = Shift::where('end_at', null)->where('closed', false)->first();

        if (! $currentShift) {
            // Redirect to start shift page if no active shift
            return redirect()->route('shifts.start');
        }

        return $next($request);
    }

    /**
     * With multiple terminals on, a POS screen needs this browser bound to a
     * drawer, that drawer open in the current shift, and the signed-in user
     * holding it (else they take it over first).
     */
    private function handleDrawer(Request $request, Closure $next): Response
    {
        if ($this->resolver->terminal() === null) {
            return redirect()->route('terminals.device');
        }

        $session = $this->resolver->current();

        if ($session === null) {
            return redirect()->route('terminals.open');
        }

        if ($session->user_id !== $request->user()?->getKey()) {
            return redirect()->route('terminals.handover');
        }

        return $next($request);
    }
}
