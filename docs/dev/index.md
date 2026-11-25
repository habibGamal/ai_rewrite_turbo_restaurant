# Technical overview

Turbo POS is a multi-purpose point of sale sold as one install per shop. It started as a restaurant POS (rewritten from the legacy AdonisJS app in `turbo_resturant/`) and is being turned into a modular product for restaurants, cafés, bakeries, fashion stores, groceries and general retail.

This handbook is for developers and for whoever installs and supports the system. Shop owners and cashiers should read the [Arabic user guide](/start/).

## The repository

| Path | What it is |
|---|---|
| `larament/` | The application: Laravel 11, Filament 5 admin panel, React 18 + Inertia 2 cashier, Pest 4 tests. |
| `turbo_resturant/` | Legacy AdonisJS app. Read-only reference for old business rules. |
| `label-agent/` | Turbo Label, the .NET 8 Windows print app for labels and hosted receipts. |
| `docs/` | This documentation site (VitePress). |

The online ordering website is a separate repository, `habibGamal/turbo_tenant`, with its own documentation. This handbook only covers its link to the POS: see [Website integration API](/dev/website-integration).

## Stack at a glance

| Layer | Technology |
|---|---|
| Backend | Laravel 11 on PHP 8.4 (production Alpine), MySQL 8 |
| Admin panel | Filament 5, Arabic RTL, at `/admin` |
| Cashier, kitchen, floor designer | React 18 + TypeScript, Inertia 2, Ant Design 5, Tailwind 4, Vite 5 |
| Real time | Laravel Reverb (Pusher protocol) + Laravel Echo |
| Queue | Database queue (`QUEUE_CONNECTION=database`) for print jobs |
| Permissions | spatie/laravel-permission, plus module and feature gating |
| Printing | ESC/POS (`mike42/escpos-php`), receipt images via Browsershot or wkhtmltoimage, Arabic shaping via `ar-php`; barcode labels and hosted receipts through the Turbo Label Windows app ([Printing](/dev/printing)) |
| AI | Remote read-only MCP server (`laravel/mcp`) with Passport OAuth ([AI assistant](/dev/ai-mcp)) |
| Tests | Pest 4 (SQLite in memory), PHPStan level 5, Pint, Cypress for E2E |

## Defaults

- Locale `ar`, currency EGP, timezone `Africa/Cairo`.
- Seeded admin: `admin@example.com` / `password` (change it on any real install).
- The vendor account is created separately with [`php artisan pos:vendor`](/dev/vendor).

## Where to go next

- New to the code: [System architecture](/dev/architecture), then [Modules & business types](/dev/modules).
- Installing a shop: [Deployment](/dev/ops/deployment), then [Vendor account & console](/dev/vendor).
- Connecting the website: [Website integration API](/dev/website-integration).
- Printing in the shop: [Printing, labels & Turbo Label](/dev/printing).
- AI analysis of shop data: [AI assistant (MCP server)](/dev/ai-mcp).
