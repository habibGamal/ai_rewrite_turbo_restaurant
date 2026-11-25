# AGENTS.md

## Project Overview

This workspace rewrites **turbo_resturant** (AdonisJS) into **larament** (Laravel + FilamentPHP + React/Inertia). The old app is reference-only — do not modify it.

- `larament/` — Primary app. Laravel 11 (`^11.9`), FilamentPHP v5 (`~5.0`) admin panel, React frontend via Inertia.js, TypeScript, Tailwind v4.
- `manage_operations/` — Ops tool for deploy/SSH management. Separate git repo (gitignored at workspace root). Laravel 12, React/Inertia, shadcn/ui.
- `turbo_resturant/` — Legacy AdonisJS app. Read-only reference for business logic.

**Arabic UI** (`APP_LOCALE=ar`). **Currency: EGP**. Timezone: `Africa/Cairo`.

## Commands

All commands for larament must use: `cd D:/ai_rewrite_turbo_restaurant/larament; <command>`

### Larament (primary)

| Task | Command |
|---|---|
| Quality gate (lint+test+static analysis) | `composer review` |
| Fix code style (Pint) | `cd D:/ai_rewrite_turbo_restaurant/larament; ./vendor/bin/pint` |
| Run tests (Pest, parallel) | `cd D:/ai_rewrite_turbo_restaurant/larament; ./vendor/bin/pest --parallel` |
| Single test file | `cd D:/ai_rewrite_turbo_restaurant/larament; ./vendor/bin/pest tests/Feature/SomeTest.php` |
| Static analysis (PHPStan level 5) | `cd D:/ai_rewrite_turbo_restaurant/larament; ./vendor/bin/phpstan analyse` |
| Build frontend | `cd D:/ai_rewrite_turbo_restaurant/larament; npm run build` |
| E2E tests (Cypress) | `cd D:/ai_rewrite_turbo_restaurant/larament; npm run cypress:run` |
| Dev (all services) | `cd D:/ai_rewrite_turbo_restaurant/larament; composer dev` |

**Never run `php artisan serve`** — the user manages dev servers themselves.

### Claude Code cloud sessions

A fresh cloud container has no `vendor/`. The SessionStart hook (`.claude/hooks/session-start.js`) runs `larament/scripts/cloud-setup.sh` automatically once this lands on the branch the session starts from. If `larament/vendor` is missing (e.g. after checking out a branch), run it yourself:

```bash
bash larament/scripts/cloud-setup.sh   # ~30 s fresh, instant when vendor/ is current
cd larament && php vendor/bin/pest --parallel
```

Why: the cloud proxy blocks GitHub zip downloads, so plain `composer install` falls back to full git clones of all ~200 packages (10+ min). `scripts/composer-prefetch.sh` shallow-fetches only the locked commit of each package into Composer's cache, then `composer install` loads everything from cache. Cloud images ship PHP 8.3, so the script passes `--ignore-platform-req=php` there (the lock targets 8.4; tests run fine on 8.3).

### manage_operations

Separate gitignored repo at `D:/ai_rewrite_turbo_restaurant/manage_operations`.

| Task | Command |
|---|---|
| Lint (ESLint) | `cd D:/ai_rewrite_turbo_restaurant/manage_operations; npm run lint` |
| Format check | `cd D:/ai_rewrite_turbo_restaurant/manage_operations; npm run format:check` |
| Type check | `cd D:/ai_rewrite_turbo_restaurant/manage_operations; npm run types` |
| Build | `cd D:/ai_rewrite_turbo_restaurant/manage_operations; npm run build` |
| Tests | `cd D:/ai_rewrite_turbo_restaurant/manage_operations; composer test` |

## Architecture (larament)

- **FilamentPHP v5 admin panel** — Resources in `app/Filament/Resources/`, pages/widgets/actions in sibling dirs under `app/Filament/`
- **React frontend** — Entry `resources/js/app.tsx`, pages in `resources/js/Pages/`, components in `resources/js/Components/`
- **Path alias** — `@/*` maps to `resources/js/*` (configured in both `tsconfig.json` and `vite.config.js`)
- **Reverb** for WebSockets (broadcasting), **Ziggy** for route names in JS
- **Database** — Local dev: MySQL on port 8085. Tests: in-memory SQLite (`phpunit.xml` overrides)
- **Helpers** — `app/Helpers.php` (autoloaded via composer `files`)
- **Repositories pattern** — `app/Repositories/`, `app/Services/`, `app/DTOs/`, `app/Enums/`

## Testing

- **Backend (Pest)** — `tests/Feature/` and `tests/Unit/`. Uses SQLite in-memory by default.
- **E2E (Cypress)** — `cypress/e2e/`. Requires running Laravel server at `localhost:8000`. Config in `cypress.config.ts`.
- **PHPStan level 5** — analyses `app/` only.

## Deployment

- Target: Alpine Linux server at `/var/www/turbo_restaurant/larament`
- Script: `larament/deploy.sh` — git pull, composer/npm install, build, migrate, cache optimizations
- Refresh (no deploy): `larament/refresh.sh` — clear cache, rebuild, restart services

## Conventions

- PRs target `master`.
- `composer review` is the pre-push quality gate: Pint → Pest → PHPStan
- FilamentPHP v5: consult `.claude/skills/filamentphp-planning/` and `.claude/skills/filamentphp-testing/` for planning/testing patterns
- MCP server / ChatGPT, Claude or Gemini connectors: follow `.claude/skills/laravel-mcp-server/`
- `lang/ar.json` is the primary translation file (Arabic UI)
- Default seed user: `admin@example.com` / `password`

## Documentation

- `docs/` (VitePress) is the single source of truth: Arabic user guide at `/` (`start/`, `guide/`, `admin/`, `integrations/`) and English developer handbook under `docs/dev/` (with `docs/dev/ops/`). Follow `docs/dev/style-guide.md`.
- Any PR that changes behaviour, settings, an API, or deployment must update the matching `docs/` page in the same PR and add a line to `CHANGELOG.md`.
- Only these `.md` locations are allowed: `docs/**` (excluding `docs/node_modules`), `.workspace/**`, `README.md`, `CHANGELOG.md`, `AGENTS.md`, `CLAUDE.md`, `label-agent/README.md`, `larament/README.md`, `.claude/**`, `larament/resources/ai/**`, and `turbo_resturant/**`.
- Run `bash scripts/docs-check.sh` (or `bash scripts/docs-check.sh --build` for full + public VitePress builds) before pushing.
- The docs theme in `docs/.vitepress/theme/` is shared by the POS, website and platform docs and is edited only in the POS repo. See `docs/dev/style-guide.md` ("Shared theme") and `bash scripts/docs-theme.sh`.

## Workspace

All drafts, plans, specs, tickets, ADRs, research, and handoffs go in `.workspace/` (see `.workspace/index.md`), never in the repo root or `docs/`.

## Agent skills

- Issue tracker -> `.workspace/agents/issue-tracker.md`
- Domain docs (glossary, ADRs) -> `.workspace/agents/domain.md`
- Coding standards -> `.workspace/CODING_STANDARDS.md`
