<?php

use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsureOrderTabAccess;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTerminalDevice;
use App\Http\Middleware\ShiftMiddleware;
use App\Http\Middleware\VerifyApiKey;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo('/admin/login');

        $middleware->web(append: [
            ResolveTerminalDevice::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ])->alias([
            'order.tab' => EnsureOrderTabAccess::class,
            'shift' => ShiftMiddleware::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'api.key' => VerifyApiKey::class,
            'module' => EnsureModuleEnabled::class,
        ]);

        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            VerifyApiKey::class,
        );

        // Allow management/start route during maintenance mode
        $middleware->preventRequestsDuringMaintenance(except: [
            'api/management/start',
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // AI clients (e.g. ChatGPT) may not send "Accept: application/json"; the MCP
        // endpoint must still answer 401 + WWW-Authenticate so OAuth starts, never
        // redirect to the login page.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'mcp/*') || $request->expectsJson(),
        );
    })->create();
