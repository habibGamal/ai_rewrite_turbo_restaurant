<?php

namespace App\Providers;

use App\Auth\BadgeService;
use App\Mcp\McpAccess;
use App\Models\User;
use Carbon\CarbonInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

/**
 * OAuth (Passport) and limits for the «المساعد الذكي» MCP server.
 */
class McpServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Passport::tokensExpireIn(CarbonInterval::hours(8));
        Passport::refreshTokensExpireIn(CarbonInterval::days(30));

        // The consent page refuses badge (POS-only) sessions and users who may
        // not use the assistant, before any token is issued.
        Passport::authorizationView(function (array $parameters) {
            /** @var Request $request */
            $request = $parameters['request'];
            $user = $parameters['user'];

            $denial = match (true) {
                $request->hasSession() && $request->session()->get(BadgeService::SESSION_KEY) === true => 'الدخول بالبادج لنقطة البيع فقط. سجّل الخروج ثم ادخل بالبريد الإلكتروني وكلمة المرور لربط المساعد الذكي.',
                ! $user instanceof User => 'غير مصرح.',
                default => app(McpAccess::class)->denial($user),
            };

            return $denial === null
                ? response()->view('mcp.authorize', $parameters)
                : response()->view('mcp.denied', ['message' => $denial], 403);
        });

        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(60)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Read here, after .env is loaded (bootstrap/app.php runs too early for env()).
        $proxies = config('app.trusted_proxies');
        if (is_string($proxies) && trim($proxies) !== '') {
            TrustProxies::at(trim($proxies) === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
    }
}
