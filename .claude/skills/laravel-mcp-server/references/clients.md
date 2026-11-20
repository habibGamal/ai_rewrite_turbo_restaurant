# Client facts

"Seen" = observed in server logs while connecting successfully (2026-11). "Configured" = allowed and expected to work, not yet watched end to end. Re-check with the temporary request logger (SKILL.md §4) when a client changes behaviour.

## Redirect allowlist (config/mcp.php)

```php
'redirect_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env(
    'MCP_REDIRECT_DOMAINS',
    'https://claude.ai,https://claude.com,https://chatgpt.com,https://chat.openai.com,'
    .'https://oauth-redirect.googleusercontent.com,https://oauth-redirect-sandbox.googleusercontent.com,'
    .'https://oauth-redirect-test.googleusercontent.com,https://vertexaisearch.cloud.google.com,'
    .'http://localhost,http://127.0.0.1',
))))),
'custom_schemes' => ['cursor', 'vscode'],
```

Matching is `Str::startsWith($uri, $entry.'/')`. `http://localhost` / `http://127.0.0.1` entries additionally allow any localhost port.

## ChatGPT (connectors / "custom MCP server", needs Developer mode) — seen

- Discovery: `POST /mcp/...` without `Accept: application/json` (→ must get 401, not 302), then `/.well-known/oauth-protected-resource/<path>`, `/.well-known/oauth-authorization-server`, `/.well-known/openid-configuration` (404 is fine).
- Registers: `client_name: "ChatGPT"`, `redirect_uris: ["https://chatgpt.com/connector/oauth/<id>"]`, `token_endpoint_auth_method: "none"`.
- Token request carries `resource=<mcp url>`; UA `openai-connectors-oauth/1.0`. Tool calls: UA `openai-mcp/1.0.0`.
- Caches discovered endpoints in the form — after a server fix, Cancel and create the connector again. "Some app settings were rejected" with `http://` endpoints in the form = metadata wasn't https at discovery time.
- Steps for the user: Settings → Apps & Connectors → enable Developer mode → create/add custom MCP server → URL `https://<host>/mcp/<name>`, Authentication **OAuth**, leave advanced OAuth fields empty.

## Gemini app (gemini.google.com → Settings → Connectors → + Custom) — seen

- Probes `HEAD` and `GET` on the MCP URL (405 is fine), then discovery, then `POST` → 401.
- Registers with UA `OpenAuth`, `client_name: "Google"`, `token_endpoint_auth_method: "client_secret_post"` (a public client still works), and **six** redirect URIs:
  `https://oauth-redirect{,-sandbox,-test}.googleusercontent.com/{r,a}/user_bound_custom-mcp-<id>`.
  Any one rejected → UI says "The Google redirect URL was rejected by the server. Enter your OAuth client ID and client secret".
- Uses the `/r/` URI at authorize time; token request carries `resource`. Tool calls UA `Google`.
- Steps for the user: URL `https://<host>/mcp/<name>`, leave Client ID / secret empty, sign in, Authorize.

## Gemini Enterprise (Connected apps → Add MCP server) — configured

Redirect `https://vertexaisearch.cloud.google.com/oauth-redirect`. Streamable HTTP only, public https required.

## Gemini CLI — configured

Redirect `http://localhost:<random-port>/oauth/callback` (older versions `:7777`). `gemini mcp add` with the http URL.

## Claude Code — stdio seen; http configured

- stdio: project `.mcp.json` `{ "type": "stdio", "command": "<abs php>", "args": ["<abs artisan>", "mcp:start", "<name>"], "env": { "MCP_USER_ID": "<id>" } }`. New session + approve.
- http: `claude mcp add --transport http <name> https://<host>/mcp/<name>`, then `/mcp` in a terminal session → authenticate (localhost callback).

## claude.ai / Claude desktop & mobile — configured

Settings → Connectors → Add custom connector → MCP URL. Redirect on `https://claude.ai/...` (and `https://claude.com/...`).
