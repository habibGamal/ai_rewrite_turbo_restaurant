# AGENTS.md

## Project Overview

This workspace rewrites **turbo_resturant** (AdonisJS) into **larament** (Laravel + FilamentPHP + React/Inertia). The old app is reference-only — do not modify it.

- `larament/` — Primary app. Laravel 11, FilamentPHP v4 admin panel, React frontend via Inertia.js, TypeScript, Tailwind v4.
- `manage_operations/` — Ops tool for deploy/SSH management. Separate git repo (gitignored at workspace root). Laravel 12, React/Inertia, shadcn/ui.
- `turbo_resturant/` — Legacy AdonisJS app. Read-only reference for business logic.

**Arabic UI** (`APP_LOCALE=ar`). **Currency: EGP**. Timezone: `Africa/Cairo`.

## Commands

All commands for larament must use: `cd e:\AI_rewirte\larament; <command>`

### Larament (primary)

| Task | Command |
|---|---|
| Quality gate (lint+test+static analysis) | `composer review` |
| Fix code style (Pint) | `cd e:\AI_rewirte\larament; ./vendor/bin/pint` |
| Run tests (Pest, parallel) | `cd e:\AI_rewirte\larament; ./vendor/bin/pest --parallel` |
| Single test file | `cd e:\AI_rewirte\larament; ./vendor/bin/pest tests/Feature/SomeTest.php` |
| Static analysis (PHPStan level 5) | `cd e:\AI_rewirte\larament; ./vendor/bin/phpstan analyse` |
| Build frontend | `cd e:\AI_rewirte\larament; npm run build` |
| E2E tests (Cypress) | `cd e:\AI_rewirte\larament; npm run cypress:run` |
| Dev (all services) | `cd e:\AI_rewirte\larament; composer dev` |

**Never run `php artisan serve`** — the user manages dev servers themselves.

### manage_operations

| Task | Command |
|---|---|
| Lint (ESLint) | `cd e:\AI_rewirte\manage_operations; npm run lint` |
| Format check | `cd e:\AI_rewirte\manage_operations; npm run format:check` |
| Type check | `cd e:\AI_rewirte\manage_operations; npm run types` |
| Build | `cd e:\AI_rewirte\manage_operations; npm run build` |
| Tests | `cd e:\AI_rewirte\manage_operations; composer test` |

## Architecture (larament)

- **FilamentPHP v4 admin panel** — Resources in `app/Filament/Resources/`, pages/widgets/actions in sibling dirs under `app/Filament/`
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

- `composer review` is the pre-push quality gate: Pint → Pest → PHPStan
- FilamentPHP v4: consult `.github/docs/filament-v4-upgrade-guide.md` and `.github/skills/` for planning/testing patterns
- `lang/ar.json` is the primary translation file (Arabic UI)
- Default seed user: `admin@example.com` / `password`
