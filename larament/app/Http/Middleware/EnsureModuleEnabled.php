<?php

namespace App\Http\Middleware;

use App\Modules\Module;
use App\Services\FeatureService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `module:<name>[,<name>…]`: answers 404 unless every named
 * module is switched on, so a clothing shop has no /kitchen at all.
 */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string ...$modules): Response
    {
        $features = app(FeatureService::class);

        foreach ($modules as $name) {
            $module = Module::tryFrom($name);

            if ($module === null || ! $features->moduleEnabled($module)) {
                abort(404);
            }
        }

        return $next($request);
    }
}
