<?php

use App\Http\Middleware\EnsureMcpAccess;
use App\Mcp\Servers\BusinessServer;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Http\Middleware\CheckToken;

// OAuth discovery (/.well-known/...) and dynamic client registration
// (/oauth/register) for AI clients. Passport issues the tokens
// (/oauth/authorize, /oauth/token); users sign in with their normal account.
Mcp::oauthRoutes();

// «المساعد الذكي»: read-only business data for ChatGPT, Claude and Gemini,
// acting as the user who approved the connection. Remote only.
Mcp::web('/mcp/pos', BusinessServer::class)
    ->middleware([
        'auth:api',
        CheckToken::using(Registrar::OAUTH_SCOPE),
        EnsureMcpAccess::class,
        'throttle:mcp',
    ]);
