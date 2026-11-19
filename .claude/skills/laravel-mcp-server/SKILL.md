---
name: laravel-mcp-server
description: Build or fix an MCP server inside a Laravel app (laravel/mcp) that real AI clients can connect to — Claude Code, claude.ai, ChatGPT connectors and the Gemini app — including remote OAuth with Laravel Passport, reusing existing laravel/ai tools, exposing skills as prompts, and the client-specific gotchas that make connections fail. Use this whenever the user wants to expose a Laravel app's data or tools over MCP, make "an MCP server" / "connector" / "custom app" for ChatGPT, Gemini or Claude, add OAuth to an MCP endpoint, or debug errors like "redirect URL was rejected", "Some app settings were rejected", http instead of https OAuth URLs, or a connector stuck at login — even if they don't say "laravel/mcp".
---

# Laravel MCP server for Claude, ChatGPT and Gemini

A working recipe, proven end to end on Laravel 13 + laravel/mcp 1.x + Passport 13. Each step exists because a real client failed without it, so keep the "why" in mind when a project differs.

Code templates live in `references/code.md`; per-client facts (redirect URIs, user agents, where to click) in `references/clients.md`. Read them when you reach the step that needs them.

**In this repo, read `references/this-project.md` first** (Laravel 11 pins, Breeze login, guards, gating).

## 0. Look before building

- `composer.json`: laravel/mcp, laravel/passport, laravel/ai, spatie/laravel-permission, inertiajs, fortify?
- Existing AI tools (`app/Ai/Tools`, classes implementing `Laravel\Ai\Contracts\Tool`) and skills (`resources/ai/skills/**/SKILL.md`). If they exist, **bridge them instead of rewriting**: laravel/ai and laravel/mcp tools share `Illuminate\Contracts\JsonSchema\JsonSchema`, so one wrapper class exposes every tool unchanged and both consumers stay in sync.
- How users sign in (Fortify `/login`? Filament? Inertia React pages?) — the OAuth consent step goes through it.
- Roles/permissions guard names (`select guard_name, count(*) from permissions group by guard_name`).

Ask the user only what the code can't tell you: which user a local server should act as, and the public URL they'll use (tunnel or domain).

## 1. Local server first (stdio) — fastest feedback

1. `composer require laravel/mcp`.
2. Server class extending `Laravel\Mcp\Server` (template: references/code.md §Server):
   - Fill `$this->tools` / `$this->prompts` in `boot()` with **instances** (the server accepts objects, not only class names) — this is what makes the bridge possible.
   - `public int $defaultPaginationLength = 50;` — the default 15 hides tools from clients that ignore cursors.
   - Put the domain's core rules in `$instructions` (grounding, defaults, answer style). Server instructions reach every client; skills/prompts don't load automatically everywhere.
   - Resolve the acting user: the `api` guard user when remote, otherwise a configured id (`config('ai.mcp.user_id')` ← `MCP_USER_ID`). Call `Auth::setUser($user)` so policies see the same user.
3. `BridgedTool` (one class wrapping any laravel/ai tool) and `SkillPrompt` (SKILL.md → MCP prompt). Mark read-only tools `#[IsReadOnly]`.
4. `routes/ai.php`: `Mcp::local('name', Server::class);` (the package loads this file automatically).
5. Test over stdio by piping JSON lines (initialize, tools/list, prompts/list, tools/call, prompts/get) into `MCP_USER_ID=… php artisan mcp:start name`. Also call one tool with a branch/resource the user may not access and confirm it is refused.
6. Claude Code: project `.mcp.json` with `type: stdio`, absolute PHP path and absolute `artisan` path (on Windows `C:/php8/php.exe`, `D:/proj/artisan`), `env: { MCP_USER_ID }`. Servers load at session start — tell the user to open a new session and approve it.

## 2. Remote server with OAuth

### Install
- `composer require laravel/passport`. If resolution fails mentioning `lcobucci/jwt … ext-sodium`, enable `extension=sodium` in php.ini (back it up first) — it ships with PHP.
- `php artisan vendor:publish --tag=passport-migrations`, then run **only** those files (`php artisan migrate --path=database/migrations/<file>`) if `migrate:status --pending` shows unrelated old migrations — never "fix" someone's out-of-sync migration table as a side effect.
- `php artisan passport:keys` (keys land in `storage/*.key`, normally gitignored — check).
- `php artisan vendor:publish --tag=mcp-config`.

### Wire it (templates in references/code.md)
- `User`: `use HasApiTokens` + `implements OAuthenticatable`.
- `config/auth.php`: `'api' => ['driver' => 'passport', 'provider' => 'users']`. First grep for existing `auth:api` users (Sanctum apps usually use `sanctum`, so it's free).
- Provider boot: `Passport::authorizationView(fn ($p) => view('mcp::authorize', $p))`, `tokensExpireIn(8h)`, `refreshTokensExpireIn(30d)`.
- `routes/ai.php`:
  ```php
  Mcp::oauthRoutes();   // /.well-known/* discovery + POST /oauth/register (dynamic client registration)
  Mcp::web('/mcp/name', Server::class)
      ->middleware(['auth:api', CheckToken::using(Registrar::OAUTH_SCOPE), EnsureFeatureAccess::class]);
  ```
  The access middleware applies the same gate the in-app feature uses (plan, toggle, permission).

### The compatibility checklist — each item broke a real client

1. **Redirect allowlist (`config/mcp.php` `redirect_domains`).** Never `*` (anyone could register a look-alike client and phish consent). Entries are URL **prefixes**; the package appends `/` to each, so list origins or paths ending in `/`, never a partial path. **One disallowed URI rejects the whole registration**, and Gemini sends six at once. Allowed set: see references/clients.md. Make it env-overridable.
2. **Always 401, never 302, on the MCP route.** ChatGPT doesn't send `Accept: application/json`, so `auth:api` redirected it to `/login` and OAuth never started. In `bootstrap/app.php`: `shouldRenderJsonWhen(fn ($r) => $r->is('api/*', 'mcp/*') || $r->expectsJson())`. Expected: `401` + `WWW-Authenticate: Bearer realm="mcp", resource_metadata=…` for any Accept.
3. **https in OAuth metadata behind a tunnel/proxy.** Metadata is built from `url('/')`; without trusted proxies it says `http://` and ChatGPT/Gemini refuse. Call `TrustProxies::at(...)` from a **service provider** reading `config('app.trusted_proxies')` — `env()` inside `bootstrap/app.php`'s `withMiddleware` returns null because `.env` isn't loaded yet.
4. **Permissions under the token guard.** With spatie/laravel-permission and roles defined for `web`, `$user->can()` on an `auth:api` request looks up `api` permissions and denies everyone. Add `protected string $guard_name = 'web';` to `User` (after confirming all roles/permissions are `web`).
5. **Inertia login swallowing the consent page.** After an Inertia login form, Fortify's plain redirect to `/oauth/authorize` renders the Blade consent page inside Inertia's error modal (a giant icon over the login form). Bind custom Fortify `LoginResponse` and `TwoFactorLoginResponse` that return `Inertia::location($url)` when the request has `X-Inertia` and the target path starts with `/oauth/`.
6. **Vite dev server over a public URL.** While `public/hot` exists, pages load scripts from `http(s)://[::1]:5173`, so the login page is blank for the client. For tunnel testing: `npm run build` and move `public/hot` aside (restarting the dev command recreates it).
7. **Public clients are fine.** laravel/mcp registers clients as public (`token_endpoint_auth_method: none`) even when Gemini asks for `client_secret_post`; with PKCE both Gemini and ChatGPT exchange tokens successfully. Both also send a `resource` parameter. A 404 on `/.well-known/openid-configuration` is harmless.
8. **Clients cache discovery.** ChatGPT keeps the endpoints it discovered first (e.g. old `http://` ones). After any metadata fix, tell the user to cancel and create the connector again, leaving client id/secret empty.
9. **Restarts.** `php artisan serve` restarts when `.env` changes (brief 502 through a tunnel); run `php artisan config:clear` after config edits; long-running `queue:work` keeps old config — `php artisan queue:restart`.

## 3. Verify like a client would

Run `scripts/check-remote-mcp.sh <base-url> <mcp-path>` (from this skill's folder). It checks the 401 + header, https metadata, allowlist accept/deny for every client's redirect shape, and the signed-out authorize → `/login` redirect, and revokes the clients it registers.

For the full browser flow (only on the user's own local/test app, with seeded test credentials):
- Register a client with redirect `http://localhost:9999/callback`; run a tiny catcher (`php -S localhost:9999 index.php`) that saves `$_GET` **only when `code` is present** (the browser's `/favicon.ico` request otherwise overwrites it).
- Open `/oauth/authorize?...&code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM&code_challenge_method=S256&scope=mcp:use`, sign in, click Authorize.
- Exchange at `/oauth/token` with `code_verifier=dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk` (the RFC 7636 pair), then call initialize / tools/list / tools/call with the Bearer token. Revoke the test client afterwards.

## 4. When a client still fails: look at what it sends

Don't guess. Add a temporary listener in a service provider that logs `RequestHandled` for `oauth/*`, `.well-known/*`, `mcp/*` (method, URI, status, user agent, the body of `oauth/register` / `oauth/token` minus `code`, `code_verifier`, `client_secret`, `refresh_token`, and the body of 4xx responses). Ask the user to retry, read `storage/logs/laravel.log`, fix, and **remove the listener** once connected. User agents: Gemini `Google` / `OpenAuth`; ChatGPT `Python … aiohttp`, `openai-mcp/1.0.0`, `openai-connectors-oauth/1.0`.

## 5. Tell the user how to connect

Give each client's steps from references/clients.md, the exact MCP URL, and these notes: leave client id/secret empty (dynamic registration), trycloudflare-style URLs change per run (production needs a fixed https domain and specific `TRUSTED_PROXIES`), and the browser login uses their normal app account.
