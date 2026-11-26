# End-to-end test stack (POS + website)

`e2e/` holds a Docker stack that runs the POS and the website (`turbo_tenant`) side by side, links them the way a real branch is linked, and runs [e2e](https://e2e.com/docs) tests across both. Agent steps (`agent.act`, `agent.assert`) run on the Antigravity CLI (`agy`) over ACP.

## What runs

| Service | Image | Host URL | Notes |
| --- | --- | --- | --- |
| `db` | `mariadb:11.4` | `127.0.0.1:33306` (root / `e2e`) | Databases `pos_e2e`, `site_e2e`, and the tenant DB `tenante2e` |
| `pos` | `turbo-e2e-php:1` (PHP 8.4 Alpine) | `http://localhost:8001` | `admin@example.com` / `password`, web orders module on, day and shift open |
| `site` | same image | `http://e2e.localhost:8000` | Tenant `e2e` (E2eSeeder fixtures), customer `admin@example.com`, store admin `store-admin@example.com` (both `password`) |
| `site-queue` | same image | | Sends website orders to the POS |
| `mailpit` | `axllent/mailpit` | `http://localhost:8025` | Catches every email from both apps |
| `tunnel` (opt-in) | `cloudflare/cloudflared` | printed by `stack.sh tunnel-url` | Public HTTPS URL for Paymob redirects and webhooks |
| `runner` (opt-in) | Node 24 slim + Chromium headless shell | | For machines without Node/WSL and for CI |

Inside Docker the website reaches the POS at `http://pos:8000` (branch link and products repo), and the POS reaches the website at `http://e2e-shop.test:8000` (a network alias that is also a tenant domain), so status callbacks work without the tunnel.

## Two install modes

| Mode | Command | Downloads | Uses |
| --- | --- | --- | --- |
| Normal (default) | `e2e/stack.sh install` once, then `up` | about 1 to 1.3 GB the first time | `vendor/` and `node_modules` in container volumes; builds both front ends |
| Reuse host | `e2e/stack.sh up --reuse-host` | about 150 MB of images | The `vendor/` and `public/build` already in each repo on the host |

Both modes keep `bootstrap/cache` and `storage` in container volumes, so nothing in the host repos is changed except `public/build` when the normal install builds assets. The mode is remembered in `e2e/.e2e/mode`.

## Quick start

```bash
# Repos side by side: <dir>/ai_rewrite_turbo_restaurant and <dir>/turbo_tenant (else set SITE_DIR in e2e/.env.e2e)
e2e/stack.sh up --reuse-host      # or: e2e/stack.sh install && e2e/stack.sh up
e2e/stack.sh seed                 # fresh DBs, tenant, plugins, POS <-> website link
e2e/stack.sh test                 # needs Node 24 (inside WSL on Windows) and agy for agent steps
E2E_AGENT=none e2e/stack.sh test tests/stack.e2e.ts   # exact checks only, no model
e2e/stack.sh down                 # keep data; `down --wipe` drops all volumes
```

`seed` runs `migrate:fresh` on both apps, then `e2e/seed/pos.php` (API key, website URL, integration token, test products `E2E-ESPRESSO` / `E2E-LATTE`, a stocked item so the day can open, day + shift) and `e2e/seed/site.php` (tenant domains, branch link + `pos_api_key`, products repo, product mappings 15 → `E2E-ESPRESSO` and 16 → `E2E-LATTE`, Paymob test keys).

## Paymob test payments and the tunnel

1. Put the Paymob **test** credentials in `e2e/.env.e2e` (`E2E_PAYMOB_SECRET_KEY`, `E2E_PAYMOB_PUBLIC_KEY`, `E2E_PAYMOB_INTEGRATION_IDS`, `E2E_PAYMOB_HMAC_SECRET`, plus the test card fields). The file is git-ignored.
2. `e2e/stack.sh up --tunnel` then `e2e/stack.sh seed`. Seeding adds the tunnel host as a tenant domain and switches online payment on in test mode.
3. Open the storefront through the tunnel URL (`SITE_PUBLIC_URL` in `e2e/.e2e/urls.env`). The website builds Paymob's `redirection_url` and `notification_url` from the request host (`OrderPaymentDispatcher`), and `X-Forwarded-Proto: https` from Cloudflare makes them HTTPS, so Paymob's webhook reaches `/api/webhooks/paymob`.

Without `CLOUDFLARE_TUNNEL_TOKEN` the stack opens a free quick tunnel with a new `*.trycloudflare.com` address on every start; rerun `seed` after restarting it. For a fixed address, create a tunnel in Cloudflare Zero Trust that routes your hostname to `http://site:8000`, and set `CLOUDFLARE_TUNNEL_TOKEN`, `E2E_TUNNEL_HOSTNAME` and `E2E_TUNNEL_COMMAND="tunnel --no-autoupdate run"`.

Pages opened through the tunnel travel over the internet; everything else stays on the machine.

## Agent steps on agy

`e2e/e2e.config.ts` uses the generic `acp()` executor from `@e2e-dev/acp` (not in the e2e docs yet; the docs show only Claude Code and Codex):

```ts
acp({ command: 'agy', args: ['acp', '--process-per-session'], model: process.env.E2E_AGY_MODEL })
```

- `--process-per-session` is needed because e2e gives the agent its step tools through an HTTP MCP server.
- `E2E_AGY_MODEL` must be a model id agy offers; a wrong value fails with the list of offered ids. Extra agy flags (for example an effort level) go in `E2E_AGY_ARGS`.
- Sign in once with `agy acp --login` (or an interactive `agy` session). agy must be installed where the tests run, so agent tests run from the host or WSL, not in the `runner` container.
- e2e gives the agent only its step tools. If agy calls its own file, shell or web tools, the step fails.
- Passed `agent.act` steps are cached in `e2e/.e2e/cache` and replay without calling agy.

## Tests

| File | What it checks | Needs a model |
| --- | --- | --- |
| `tests/stack.e2e.ts` | POS accepts the shared API key and rejects a wrong one; storefront shows the e2e menu | no |
| `tests/web-order-to-pos.e2e.ts` | Customer orders on the website, cashier accepts on the POS, website shows the order in preparation | yes (agy) |

The website repo keeps its own Playwright suite in `tests/e2e`; it targets the same `http://e2e.localhost:8000` tenant, so `npm run test:e2e` there works against this stack after `seed`.

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| `vendor/ is missing` in container logs | Normal mode: run `stack.sh install`. Reuse mode: run `composer install` in that repo on the host |
| Storefront loads without styles | `public/build` is missing: run `npm run build` in the website repo, or `stack.sh install` |
| Website order stays unsent | `stack.sh logs site-queue`; check the branch link is `http://pos:8000` and the POS day is open (`stack.sh seed`) |
| Paymob webhook never arrives | The order was placed on `e2e.localhost`, not on the tunnel URL; place it through `SITE_PUBLIC_URL` |
| TLS errors while building behind a proxy that re-signs TLS | Copy the proxy CA to `e2e/docker/php/certs/<name>.crt` and rebuild |
