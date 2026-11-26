#!/usr/bin/env bash
# Prepares a Laravel app that is bind-mounted from the host, then runs the given command.
set -euo pipefail

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/public bootstrap/cache

if [ ! -f vendor/autoload.php ]; then
  echo "[e2e] vendor/ is missing in $(pwd). Run: e2e/stack.sh install (or use --reuse-host after composer install on the host)." >&2
  exit 1
fi

# bootstrap/cache is a container-only volume, so host caches never leak in (and we never touch the host's).
if [ ! -f bootstrap/cache/packages.php ]; then
  php artisan package:discover --ansi >/dev/null
fi

exec "$@"
