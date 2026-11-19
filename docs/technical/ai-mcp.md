# AI assistant (MCP server)

«المساعد الذكي» is a **read-only, remote** [MCP](https://modelcontextprotocol.io) server inside the POS. ChatGPT, Claude, Gemini (and CLIs such as Claude Code) connect to it over HTTP with OAuth and answer business questions from the shop's own data, acting as the user who approved the connection. There is no local stdio server.

Built on `laravel/mcp` (~1.1.2) and `laravel/passport` (~13.11). Code in `app/Mcp`; the skill `.claude/skills/laravel-mcp-server/` holds the patterns and client gotchas.

## Access

| Gate | Where |
|---|---|
| Vendor feature `OptionalFeature::AI_ASSISTANT` (`feature_ai_assistant_enabled`, off by default) | Vendor console |
| Permission `use_ai_assistant` (the vendor always may) | Roles screen |
| Report permissions | Each tool is listed and callable only when the user may open the report it mirrors; module tools only when their module is on. |

`App\Mcp\McpAccess::denial()` returns the Arabic reason or `null`; the OAuth consent page and the endpoint both ask it. Badge sessions can't approve a connection: users sign in with email and password.

## Endpoints

`routes/ai.php`:

- `Mcp::oauthRoutes()`: `/.well-known/oauth-*` discovery and dynamic client registration (`/oauth/register`). Passport issues tokens at `/oauth/authorize` and `/oauth/token`.
- `Mcp::web('/mcp/pos', BusinessServer::class)` behind `auth:api`, the MCP scope, `EnsureMcpAccess` and `throttle:mcp` (60 requests/minute per user).

Refresh tokens last 30 days, so a connection stays alive 30 days after its last use.

`MCP_REDIRECT_DOMAINS` (`config/mcp.php`) lists the redirect URI prefixes clients may register. The default covers claude.ai / claude.com, chatgpt.com / chat.openai.com, all Google OAuth redirect hosts (Gemini registers six at once, and one disallowed URI rejects the whole registration) and `http://localhost` / `http://127.0.0.1` on any port. Never use `*`.

## Server contents

`App\Mcp\Servers\BusinessServer`:

- **Tools** (`BusinessServer::TOOLS`): business context, entity search, sales summary and breakdown, product performance and detail, orders, shifts, expenses and revenues, customers (phones masked unless the user has `view_customers`), returns, staff, audit events, inventory status and movements, purchases and suppliers, and module tools (kitchen, delivery, web orders, manufacturing yield, equipment and fees). All are annotated `#[IsReadOnly]` (the attribute is not inherited: put it on every tool class).
- **Prompts**: 8 playbooks in `resources/ai/playbooks/*.md` (daily briefing, month review, menu engineering, cash reconciliation, loss prevention, inventory health, customer retention, staffing by hour). Front matter `requires:` lists the tools a playbook needs; it is listed only when the user may call them.
- **Resources**: `pos://glossary` (Arabic business terms) and `pos://business-profile`.
- Instructions name the shop and its business type.
- Evaluation questions: `larament/docs/ai/mcp-evaluation.md`.

## Direct queries

`OptionalFeature::AI_QUERIES` (`feature_ai_queries_enabled`, off by default) adds `describe_data_model` and `run_readonly_query`, for the **vendor and admins only** (`AiQueryAccess`).

- Queries may read only the `ai_*` views (`App\Mcp\Support\AiViews`: orders, order items, products, categories, customers, payments, shifts, staff, sales points, drivers, expenses, revenues, returns, stock, warehouses, inventory movements, suppliers, purchases…). No users, passwords, settings or phone numbers.
- `ReadOnlyQuery` accepts a single `SELECT`, runs it in a read-only transaction with a 5-second timeout and returns at most 500 rows.
- Optional separate MySQL user: set `DB_AI_USERNAME` / `DB_AI_PASSWORD` and create it with the statements printed by `php artisan mcp:readonly-grants [username] [--host=]` (`GRANT SELECT` on the views only). Empty = the default connection, still read-only.
- The views are created by migration on MySQL. On SQLite (tests) `AiViews::ensure()` creates them lazily, because SQLite refuses later table rebuilds while a view reads the table. When a view changes, add a migration that recreates it.

## Admin pages

- `/admin/ai-assistant` (`AiConnections`, «المساعد الذكي»): server URL with copy button, connect steps for ChatGPT, Claude, Gemini and Claude Code, and the live connections (app, user, last refresh, last use) with «إلغاء الربط», which revokes the access token and its refresh tokens. Users see their own connections; admins see everyone's.
- `/admin/ai-assistant-log` (`AiToolCallLog`, «سجل المساعد الذكي»): every tool call (`mcp_tool_calls`), pruned after 180 days by the scheduled `model:prune`.

## Install and upgrade

Handled by `deploy.sh`; for a manual install:

1. `migrate --force` creates the Passport `oauth_*` tables, the `mcp_tool_calls` table, the two feature flags and the `ai_*` views.
2. `php artisan passport:keys` once (deploy.sh runs it when `storage/oauth-private.key` is missing). Passport needs the PHP `sodium` extension (`php84-sodium` on Alpine, installed by deploy.sh).
3. The vendor switches on «المساعد الذكي» and gives `use_ai_assistant` to the right roles.
4. The site must be reachable over **HTTPS** at its public URL (`APP_URL`), since the AI clients connect from the internet.
5. Optional: the read-only database user above.

Verified with Gemini against `https://<shop>/mcp/pos`.

::: warning Nginx buffers
Installs on nginx need the larger FastCGI buffers that deploy.sh now writes, otherwise large responses fail with 502. See [Deployment](/technical/deployment#nginx-fastcgi-buffers).
:::
