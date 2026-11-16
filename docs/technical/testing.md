# Testing & conventions

## Commands

| Task | Command (in `larament/`) |
|---|---|
| Quality gate | `composer review` (Pint → Pest → PHPStan) |
| Tests | `./vendor/bin/pest --parallel` |
| One file | `./vendor/bin/pest tests/Feature/SomeTest.php` |
| Static analysis | `./vendor/bin/phpstan analyse` (level 5, `app/` only) |
| Code style | `./vendor/bin/pint` |
| E2E | `npm run cypress:run` (needs the app on `localhost:8000`) |

Tests use in-memory SQLite (`phpunit.xml`). The base branch has about 80–86 known failing tests; judge a change by **no new failures**.

## Git workflow

- Modular-core work branches off `feature/modular-core` and opens PRs into it. Do not target `master` for now.
- Never run `php artisan serve` on a shared machine; the owner manages dev servers.

## Conventions

- Arabic UI strings: write Arabic directly or via `Wording::text()`. Never `__()` on Arabic text.
- New module screens: add to `ModuleScreens::map()` / `featureMap()` and use `BelongsToModule`.
- New module routes: add `module:<name>` middleware.
- Money changes that touch shifts: write audit rows with `AuditLogger`.
- Reports: use net sales (orders total minus refunds) and include returns.
- Domain side effects go in listeners on `App\Core\Events`, not in services.
- Filament 5: see `.github/docs/filament-v4-upgrade-guide.md` and `.claude/skills/` for patterns.
