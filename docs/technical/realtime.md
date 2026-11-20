# Real-time, queue & Reverb

## Broadcasting

The POS uses **Laravel Reverb** (Pusher protocol). The browser connects with Laravel Echo using the `VITE_REVERB_*` variables baked into the build.

| Channel | Type | Events | Listeners |
|---|---|---|---|
| `shift.{shiftId}` | private, any signed-in user | `order.created`, `order.completed`, `order.cancelled`, `order.type_changed`, `order.charges_changed`, `payment.processed` | Cashier screens of that shift |
| `kitchen` | private | `KitchenTicketUpdated` | Kitchen display (Restaurant module) |
| `web-orders` | private, `web_order.manage` | `web-order.received` | Cashier web-orders tab (sound + list refresh) |

All order broadcasts are dispatched **after commit** (see [domain events](/technical/orders-and-events#domain-events)).

### Reverb down is not an error

`App\Broadcasting\ResilientBroadcastManager` (bound in `AppServiceProvider`) wraps the `reverb` and `pusher` drivers. A failed broadcast is logged as `Broadcast skipped` and never fails the request or job. Short client timeouts keep a dead Reverb from slowing requests:

```dotenv
REVERB_CONNECT_TIMEOUT=2
REVERB_TIMEOUT=5
```

So an order placed from the website is still saved when Reverb is down; the cashier just does not hear the alert until the list refreshes.

### Environment

```dotenv
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
REVERB_HOST="localhost"
REVERB_PORT=9001
REVERB_SCHEME=http
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

Run the server with `php artisan reverb:start` (as a service in production). After changing `VITE_REVERB_*`, rebuild the frontend (`npm run build`).

## Queue

`QUEUE_CONNECTION=database`. Queued work today:

| Job | Purpose |
|---|---|
| `App\Jobs\PrintOrderReceipt` | Render and print the customer receipt. |
| `App\Jobs\PrintKitchenOrder` | Print kitchen tickets per station. |
| `ExporterCsv` / `ImportCsv` | Large exports and imports from the admin panel. |

Run a worker (`php artisan queue:work`, or `queue:listen` in development). After a deploy run `php artisan queue:restart` so workers load new code.

## Scheduler

`routes/console.php` schedules the [platform sync](/technical/platform-sync) commands:

| Command | When |
|---|---|
| `platform:sync` | every minute, no overlap |
| `platform:heartbeat` | every five minutes |
| `platform:prune` | daily 04:00 |

`deploy.sh` installs the cron line `* * * * * cd /var/www/turbo_restaurant/larament && php artisan schedule:run`.
