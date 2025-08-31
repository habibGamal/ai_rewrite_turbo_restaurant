<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AddUserPermissions
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        if (auth()->check()) {
            $user = auth()->user();

            // Add permission flags to the user object
            Inertia::share('auth.user', array_merge($user->toArray(), [
                'canApplyDiscounts' => $user->canApplyDiscounts(),
                'canCancelOrders' => $user->canCancelOrders(),
                'canChangeOrderItems' => $user->canChangeOrderItems(),
            ]));
        }

        return $next($request);
    }
}
