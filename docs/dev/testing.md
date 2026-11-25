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

- Work branches off `master` and PRs target `master`.
- Never run `php artisan serve` on a shared machine; the owner manages dev servers.

## Conventions

- Arabic UI strings: write Arabic directly or via `Wording::text()`. Never `__()` on Arabic text.
- New module screens: add to `ModuleScreens::map()` / `featureMap()` and use `BelongsToModule`.
- New module routes: add `module:<name>` middleware.
- Money changes that touch shifts: write audit rows with `AuditLogger`.
- Reports: use net sales (orders total minus refunds) and include returns.
- Domain side effects go in listeners on `App\Core\Events`, not in services.
- Filament 5: see `.claude/skills/filamentphp-planning/` and `.claude/skills/filamentphp-testing/` for patterns.

## E2E (Cypress)

Cypress tests live in `larament/cypress/` (`cypress.config.ts`) and run against existing database data rather than resetting or mocking orders:

- **Commands (in `larament/`):**
  - `npm run cypress:open` or `npm run test:e2e:open` — interactive test runner.
  - `npm run cypress:run` or `npm run test:e2e` — headless run (expects the app at `http://localhost:8000` and a seeded user such as `admin@example.com`).
  - `npx cypress run --spec "cypress/e2e/orders-index.cy.ts"` — single spec.
- **Specs (`cypress/e2e/`):** `setup-verification.cy.ts`, `orders-index.cy.ts`, `manage-order.cy.ts`, `manage-web-order.cy.ts`, `orders-integration.cy.ts`.
- **Support helpers (`cypress/support/`):** `commands.ts` (`cy.login()`, `cy.navigateToOrders()`, `cy.waitForPageLoad()`) and `order-utils.ts` (`OrderHelpers`). Component testing is configured in `cypress.config.ts` with `@cypress/react18` and Vite.
