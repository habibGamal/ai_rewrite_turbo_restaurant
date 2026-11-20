#!/usr/bin/env bash
# One-shot setup for a fresh Claude Code cloud session (or any Linux box):
# installs Composer dependencies fast and prepares .env so Pest runs.
#   bash larament/scripts/cloud-setup.sh
# Safe to re-run: skips the install when composer.json/lock are unchanged.
set -euo pipefail

cd "$(dirname "$0")/.."   # larament/
export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1

# The lock targets PHP 8.4 (config.platform). Cloud images ship 8.3 and 8.4
# is not installable through the proxy; the test suite runs fine on 8.3.
platform_args=()
if ! php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);'; then
  platform_args=(--ignore-platform-req=php)
fi

hashes=vendor/.cloud-setup.sha256
if ! sha256sum --check --status "$hashes" 2>/dev/null; then
  rm -f "$hashes"
  start=$(date +%s)
  bash scripts/composer-prefetch.sh composer.lock
  composer install --no-progress "${platform_args[@]}"
  sha256sum composer.json composer.lock scripts/cloud-setup.sh scripts/composer-prefetch.sh > "$hashes"
  echo "cloud-setup: composer install finished in $(( $(date +%s) - start ))s"
else
  echo "cloud-setup: vendor/ is up to date"
fi

if [ ! -f .env ]; then
  cp .env.example .env
  php artisan key:generate --ansi
fi
