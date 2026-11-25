# Turbo POS application (`larament/`)

The Laravel application behind Turbo POS. Full documentation lives in [`../docs`](../docs): run `npm install && npm run dev` there, or read the Markdown directly.

- [Arabic user guide](../docs/start/index.md)
- [Developer overview](../docs/dev/index.md)
- [Local setup](../docs/dev/setup.md) and [Deployment](../docs/dev/ops/deployment.md)
- [Modules & business types](../docs/dev/modules.md)
- [Website integration API](../docs/dev/website-integration.md)

## Quick commands

| Task | Command |
|---|---|
| Install | `composer install && npm install` (cloud sessions: `bash scripts/cloud-setup.sh`) |
| Run everything | `composer dev` |
| Quality gate | `composer review` (Pint, Pest, PHPStan) |
| Tests | `./vendor/bin/pest --parallel` |
| Vendor account | `php artisan pos:vendor` |
| Business type | `php artisan pos:profile [type]` |
