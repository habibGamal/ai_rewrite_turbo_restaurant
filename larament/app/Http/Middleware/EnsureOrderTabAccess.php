<?php

namespace App\Http\Middleware;

use App\Models\Order;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses routes bound to an {order} unless the user holds the cashier-desk tab
 * permission that exposes that order (its type tab, or the completed / cancelled tab).
 */
class EnsureOrderTabAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $order = $request->route('order');

        if ($order === null) {
            return $next($request);
        }

        if (! $order instanceof Order) {
            $order = Order::query()->find($order);
        }

        $user = $request->user();

        if ($order instanceof Order && (! $user instanceof User || ! $user->canAccessOrder($order))) {
            abort(403, 'غير مسموح لك بهذا النوع من الطلبات');
        }

        return $next($request);
    }
}
