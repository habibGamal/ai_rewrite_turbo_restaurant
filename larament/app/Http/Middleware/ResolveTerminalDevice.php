<?php

namespace App\Http\Middleware;

use App\Models\Shift;
use App\Models\TerminalSession;
use App\Services\FeatureService;
use App\Terminals\TerminalDeviceService;
use App\Terminals\TerminalSessionResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * With multiple terminals on, tells the request which drawer this browser is
 * bound to and, when that drawer is open in the current shift, makes its
 * session the one new money rows go to. Never redirects; ShiftMiddleware
 * decides what a POS screen needs.
 */
class ResolveTerminalDevice
{
    public function __construct(
        private readonly FeatureService $features,
        private readonly TerminalDeviceService $devices,
        private readonly TerminalSessionResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->features->multiTerminalEnabled()) {
            $device = $this->devices->fromRequest($request);
            $terminal = $device?->terminal;

            if ($device !== null && $terminal !== null && $terminal->is_active) {
                $this->devices->touch($device);
                $this->resolver->setTerminal($terminal);

                $shiftId = Shift::query()->whereNull('end_at')->where('closed', false)->value('id');
                if ($shiftId !== null) {
                    $session = TerminalSession::query()
                        ->where('shift_id', $shiftId)
                        ->where('terminal_id', $terminal->getKey())
                        ->open()
                        ->with(['terminal', 'user'])
                        ->first();

                    $this->resolver->setCurrent($session);
                }
            }
        }

        return $next($request);
    }
}
