# Deployment

Production installs run on **Alpine Linux** with nginx + PHP-FPM 8.4 at `/var/www/turbo_restaurant/larament`.

## `deploy.sh`

`larament/deploy.sh` (also triggered remotely through `POST /api/management/deploy`) does, in order:

1. `git reset --hard && git pull` in `/var/www/turbo_restaurant`.
2. Installs `wkhtmltoimage` and the DejaVu / Droid fonts (Arabic receipts and barcode labels) if missing.
3. Upgrades PHP to 8.4 when needed and repoints PHP-FPM and nginx sockets. Makes sure the `sodium` extension (`php84-sodium`) is installed, which Passport needs for the AI assistant.
4. Adds the scheduler cron line.
5. Writes `APP_VERSION` from `git describe` into `.env`.
6. `composer install`, `npm install && npm run build`.
7. Fixes ownership and permissions of `storage`, `bootstrap/cache`, `public`; `storage:link`.
8. `migrate --force`, `passport:keys` (only when `storage/oauth-private.key` is missing), `db:seed --class=RolesAndPermissionsSeeder --force`, `permission:cache-reset`, config/route/view cache, `optimize`.
9. Raises PHP and nginx upload limits (300 MB) and memory (1536 MB), sets larger FastCGI buffers, then `nginx -t && rc-service nginx reload`.

`refresh.sh` clears caches, rebuilds the frontend and restarts PHP-FPM and nginx without pulling.

## Nginx FastCGI buffers

Large response headers (for example the Inertia `/orders` page) made nginx answer **502 "upstream sent too big header"**. `deploy.sh` now adds these lines after `fastcgi_pass` in `/etc/nginx/http.d/localturbo.system.conf` (it deletes and re-adds them, so running it again is safe):

```nginx
fastcgi_buffer_size 32k;
fastcgi_buffers 16 32k;
fastcgi_busy_buffers_size 64k;
```

On a server with another vhost file name, add them by hand inside the `location ~ \.php$` block and reload nginx.

## Services to keep running

| Service | Command |
|---|---|
| Web | nginx + php-fpm84 |
| Queue worker | `php artisan queue:work` (restart with `queue:restart` after deploy) |
| WebSockets | `php artisan reverb:start` |
| Scheduler | cron `* * * * * php artisan schedule:run` (installed by deploy.sh) |

## New install checklist

<div class="steps">

1. Clone, create `.env` from `.env.example`, set `APP_KEY`, `APP_URL` (the public HTTPS address, needed by the AI assistant and label print links), `DB_*`, `BROADCAST_CONNECTION=reverb`, `REVERB_*`, `MANAGEMENT_SECRET_KEY`. Optional: `DB_AI_USERNAME` / `DB_AI_PASSWORD`, `MCP_REDIRECT_DOMAINS`, `PRINT_AGENT_WAIT_SECONDS`.
2. Run `deploy.sh`.
3. `php artisan pos:vendor`, log in as the vendor, run the setup wizard.
4. In Settings: shop name and logos, printer IP, receipt footer, scale prefix.
5. Change the seeded admin password; create cashier and kitchen users.
6. Optional: website link ([Website integration](/technical/website-integration#setup)), branches ([Branches](/technical/branches)), platform pairing ([Platform sync](/technical/platform-sync)), Turbo Label on shop PCs ([Printing](/technical/printing)), AI assistant ([AI assistant](/technical/ai-mcp#install-and-upgrade)).

</div>
