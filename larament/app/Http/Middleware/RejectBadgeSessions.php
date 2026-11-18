<?php

namespace App\Http\Middleware;

use App\Auth\BadgeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A badge login opens the POS only. The admin panel needs an e-mail and
 * password login.
 */
class RejectBadgeSessions
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && $request->session()->get(BadgeService::SESSION_KEY) === true) {
            return redirect()->route('orders.index')
                ->withErrors(['error' => 'الدخول بالبادج لنقطة البيع فقط. للوحة التحكم سجّل الخروج ثم ادخل بالبريد الإلكتروني وكلمة المرور.']);
        }

        return $next($request);
    }
}
