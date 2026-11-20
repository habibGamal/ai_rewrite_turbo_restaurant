# Turbo POS

Multi-purpose point of sale for restaurants, cafés, bakeries, fashion stores, groceries and general retail: a fast React cashier, a kitchen display, inventory and purchasing, detailed reports, and a link to the `turbo_tenant` online ordering website.

| Path | What it is |
|---|---|
| [`larament/`](larament/) | The application (Laravel 11, Filament 5, React/Inertia, Pest 4). |
| [`docs/`](docs/) | Documentation site (VitePress): English technical handbook + Arabic user guide. |
| `turbo_resturant/` | Legacy AdonisJS app, read-only reference. |

## Documentation

```bash
cd docs
npm install
npm run dev     # http://localhost:5173
```

- Technical handbook: [`docs/technical/`](docs/technical/index.md) (architecture, modules, events, vendor account, settings, website API, deployment)
- دليل المستخدم بالعربي: [`docs/ar/guide/`](docs/ar/guide/index.md)

## Quick start

```bash
cd larament
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan pos:vendor     # hidden vendor account, keep the password
composer dev
```

See [Local setup](docs/technical/setup.md) and [Deployment](docs/technical/deployment.md).
