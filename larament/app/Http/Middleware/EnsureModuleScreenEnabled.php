<?php

namespace App\Http\Middleware;

use App\Modules\ModuleScreens;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin panel middleware (persistent, so Livewire updates are checked too):
 * a screen whose module is switched off answers 404, as if it did not exist.
 */
class EnsureModuleScreenEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $action = $request->route()?->getAction('uses');

        if (is_string($action)) {
            $class = str_contains($action, '@') ? strstr($action, '@', true) : $action;

            if (class_exists($class) && ! ModuleScreens::allows($class)) {
                abort(404);
            }
        }

        return $next($request);
    }
}
