# Turbo POS application (`larament/`)

The Laravel application behind Turbo POS. Full documentation lives in [`../docs`](../docs): run `npm install && npm run dev` there, or read the Markdown directly.

- [Technical overview](../docs/technical/index.md)
- [Local setup](../docs/technical/setup.md) and [Deployment](../docs/technical/deployment.md)
- [Modules & business types](../docs/technical/modules.md)
- [Website integration API](../docs/technical/website-integration.md)
- [Arabic user guide](../docs/ar/guide/index.md)

## Quick commands

| Task | Command |
|---|---|
| Install | `composer install && npm install` (cloud sessions: `bash scripts/cloud-setup.sh`) |
| Run everything | `composer dev` |
| Quality gate | `composer review` (Pint, Pest, PHPStan) |
| Tests | `./vendor/bin/pest --parallel` |
| Vendor account | `php artisan pos:vendor` |
| Business type | `php artisan pos:profile [type]` |

Older notes in this folder (`FEATURES_AND_TECH_STACK.md`, `SETTINGS_DOCUMENTATION.md`, `docs/api/web-orders-create-order.md`, …) predate the modular core; the docs site supersedes them where they differ.
