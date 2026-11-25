# Turbo POS

Multi-purpose point of sale for restaurants, cafés, bakeries, fashion stores, groceries and general retail: a fast React cashier, a kitchen display, inventory and purchasing, detailed reports, and a link to the `turbo_tenant` online ordering website.

| Path | What it is |
|---|---|
| [`larament/`](larament/) | The application (Laravel 11, Filament 5, React/Inertia, Pest 4). |
| [`docs/`](docs/) | Documentation site (VitePress): Arabic user guide at `/` + English developer handbook under `docs/dev/`. |
| `turbo_resturant/` | Legacy AdonisJS app, read-only reference. |

## Documentation

```bash
cd docs
npm install
npm run dev          # http://localhost:5173
npm run build:public # public user docs only (run from Git Bash on Windows)
```

- دليل المستخدم بالعربي: [`docs/start/`](docs/start/index.md) (`start/`, `guide/`, `admin/`, `integrations/`)
- Developer handbook: [`docs/dev/`](docs/dev/index.md) (architecture, modules, events, vendor account, settings, website API, deployment)

## Quick start

```bash
cd larament
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan pos:vendor     # hidden vendor account, keep the password
composer dev
```

See [Local setup](docs/dev/setup.md) and [Deployment](docs/dev/ops/deployment.md).
