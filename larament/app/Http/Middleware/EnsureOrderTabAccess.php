<?php

namespace App\Http\Middleware;

use App\Models\Order;
use App\Models\User;
use App\Terminals\TerminalSeparation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses routes bound to an {order} unless the user holds the cashier-desk tab
 * permission that exposes that order (its type tab, or the completed / cancelled tab).
 * While sales points are separated, also refuses an order of another sales point.
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

        $separation = app(TerminalSeparation::class);
        if ($order instanceof Order && ! $separation->canAct($order)) {
            abort(403, $separation->denialMessage($order));
        }

        return $next($request);
    }
}
