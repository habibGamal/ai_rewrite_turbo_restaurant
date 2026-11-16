# Artisan commands

| Command | Purpose |
|---|---|
| `pos:vendor [--email=] [--password=]` | Create the hidden vendor account or reset its password. See [Vendor](/technical/vendor). |
| `pos:profile [type] [--keep-modules]` | Show or set the business type. Refused after the first order. |
| `settings {get\|set\|list\|reset} [key] [value]` | Read and change settings. Refuses vendor-owned keys. |
| `products:backfill-barcodes` | Copy `products.barcode` into `product_barcodes` and report barcodes shared by more than one product. |
| `kitchen:backfill-open-orders` | Create kitchen tickets for processing orders that have items but no ticket. |
| `inventory:fix-daily-aggregation` | Rebuild daily inventory aggregates. |
| `platform:sync [--max-seconds=50] [--no-recovery]` | Send outbox batches to the sync platform (scheduled every minute). |
| `platform:heartbeat` | Report health to the platform (every five minutes). |
| `platform:backfill [--from=] [--to=] [--catalog] [--recover]` | Queue historic documents for sync. |
| `platform:prune` | Delete sent outbox rows and batches older than 30 days (daily). |
| `platform:repair-shift-dates` | Preview or repair shifts whose historical opening time was overwritten. |
| `migrate:old-data` | Import data from the legacy database (`OLD_DB_*`). |
| `app:deploy [--force]` | Run the deployment steps from inside the app. |
| `app:register-with-management` | Register this install with the operations dashboard. |
| `make:filament-action {name}` | Generator for Filament actions. |

Seeders worth running by hand: `php artisan db:seed --class=RolesAndPermissionsSeeder`.
