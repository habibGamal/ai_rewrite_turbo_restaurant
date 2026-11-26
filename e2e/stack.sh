#!/usr/bin/env bash
# POS + website end-to-end test stack.
#
#   e2e/stack.sh install            # normal mode: composer + npm inside containers (first time, on WiFi)
#   e2e/stack.sh up [--reuse-host] [--tunnel]
#   e2e/stack.sh seed               # fresh databases, links POS <-> website, Paymob test keys
#   e2e/stack.sh test [e2e args]    # runs the e2e tests from this machine (Node 24 / WSL)
#   e2e/stack.sh test --docker      # runs them in the slim runner container instead
#   e2e/stack.sh tunnel-url | logs [service] | down [--wipe] | status
#
# --reuse-host mounts the vendor/ (and built assets) you already have on the host, so
# nothing is downloaded except the small PHP/MariaDB/Mailpit images. The choice is
# remembered in e2e/.e2e/mode until you pass the other flag.
set -euo pipefail

E2E_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$E2E_DIR"
mkdir -p .e2e

ENV_FILE="$E2E_DIR/.env.e2e"
[ -f "$ENV_FILE" ] || cp "$E2E_DIR/.env.e2e.example" "$ENV_FILE"
set -a
# shellcheck disable=SC1090
. "$ENV_FILE"
set +a

: "${SITE_PORT:=8000}" "${POS_PORT:=8001}"
: "${E2E_WEB_API_KEY:=193b6c8a03c1e8d097b8b942ded69113448c1239c66c47ba}"
: "${E2E_INTEGRATION_TOKEN:=6f116e325584c28f9df277036fec3b9567db489bfc9e28f7}"
export SITE_PORT POS_PORT E2E_WEB_API_KEY E2E_INTEGRATION_TOKEN

log() { printf '\033[1;36m[e2e]\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m[e2e]\033[0m %s\n' "$*" >&2; exit 1; }

mode() { cat .e2e/mode 2>/dev/null || echo full; }
set_mode_from_args() {
  for a in "$@"; do
    case "$a" in
      --reuse-host) echo reuse-host > .e2e/mode ;;
      --full) echo full > .e2e/mode ;;
    esac
  done
}

compose() {
  local files=(-f compose.yml)
  [ "$(mode)" = full ] && files+=(-f compose.full.yml)
  docker compose --env-file "$ENV_FILE" "${files[@]}" "$@"
}

has_flag() { local f="$1"; shift; for a in "$@"; do [ "$a" = "$f" ] && return 0; done; return 1; }

cmd_install() {
  echo full > .e2e/mode
  log "Building the PHP image"
  compose build pos
  log "composer install (POS + website) into container volumes"
  compose --profile install run --rm install-php \
    "cd /app/pos/larament && composer install --no-interaction --no-progress --no-scripts --ignore-platform-req=php \
     && cd /app/site && composer install --no-interaction --no-progress --no-scripts --ignore-platform-req=php"
  if has_flag --skip-assets "$@"; then
    log "Skipping front-end build (--skip-assets): using the public/build already in each repo"
  else
    log "npm ci + build (POS + website); writes each repo's public/build"
    compose --profile install run --rm install-assets \
      "cd /app/pos/larament && npm ci --no-audit --no-fund && npm run build \
       && cd /app/site && npm ci --no-audit --no-fund && npm run build"
  fi
  log "Install done. Next: e2e/stack.sh up && e2e/stack.sh seed"
}

cmd_up() {
  set_mode_from_args "$@"
  if [ "$(mode)" = reuse-host ]; then
    [ -f "${POS_DIR:-..}/larament/vendor/autoload.php" ] || die "POS vendor/ missing on the host. Run composer install there, or use: stack.sh install"
    [ -f "${SITE_DIR:-../../turbo_tenant}/vendor/autoload.php" ] || die "Website vendor/ missing on the host (SITE_DIR=${SITE_DIR:-../../turbo_tenant})"
  fi
  local services=(db mailpit pos site site-queue)
  local profiles=()
  if has_flag --tunnel "$@"; then
    profiles+=(--profile tunnel)
    services+=(tunnel)
  fi
  log "Starting stack in $(mode) mode"
  compose "${profiles[@]}" up -d "${services[@]}"
  wait_http "http://localhost:${POS_PORT}/up" POS
  wait_http "http://localhost:${SITE_PORT}/up" Website
  log "POS:     http://localhost:${POS_PORT}  (admin@example.com / password)"
  log "Website: http://e2e.localhost:${SITE_PORT}"
  log "Mailpit: http://localhost:${MAILPIT_PORT:-8025}"
  if has_flag --tunnel "$@"; then
    log "Tunnel:  $(tunnel_url || echo 'starting... run: e2e/stack.sh tunnel-url')"
  fi
}

wait_http() {
  local url="$1" name="$2"
  for _ in $(seq 1 90); do
    curl -fsS -o /dev/null --noproxy '*' "$url" 2>/dev/null && return 0
    sleep 2
  done
  die "$name did not answer at $url. See: e2e/stack.sh logs"
}

tunnel_url() {
  if [ -n "${E2E_TUNNEL_HOSTNAME:-}" ]; then echo "https://${E2E_TUNNEL_HOSTNAME}"; return 0; fi
  local url=""
  for _ in $(seq 1 30); do
    url="$(compose --profile tunnel logs tunnel 2>/dev/null | grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' | tail -1 || true)"
    [ -n "$url" ] && { echo "$url"; return 0; }
    sleep 2
  done
  return 1
}

site_artisan() { compose exec -T site php artisan "$@"; }
pos_artisan() { compose exec -T pos php artisan "$@"; }

cmd_seed() {
  local domains="e2e.localhost,e2e-shop.test"
  local tunnel=""
  if compose --profile tunnel ps --status running --services 2>/dev/null | grep -qx tunnel; then
    tunnel="$(tunnel_url || true)"
    [ -n "$tunnel" ] && domains="$domains,${tunnel#https://}"
  fi

  log "POS: fresh database"
  pos_artisan migrate:fresh --seed --force
  compose exec -T \
    -e E2E_WEB_API_KEY -e E2E_INTEGRATION_TOKEN \
    -e E2E_WEBSITE_URL="http://e2e-shop.test:8000" \
    pos php /e2e-seed/pos.php

  log "Website: fresh central + tenant databases"
  compose exec -T db mariadb -uroot -pe2e -e "DROP DATABASE IF EXISTS tenante2e"
  site_artisan migrate:fresh --seed --force
  site_artisan plugin:discover
  site_artisan app:create-tenant --id=e2e --domain=e2e.localhost
  for p in catalog-products locations-delivery cart-engine order-management payment-processing \
           guest-checkout coupons-promotions combo-packages customer-favorites product-reviews \
           hero-banners cms-pages pos-integration; do
    site_artisan tenant:plugin:activate e2e "$p"
  done
  site_artisan tenants:plugin:migrate --force || true
  compose exec -T -e E2E_INTEGRATION_TOKEN site php artisan tenants:seed --tenants=e2e --class='Database\Seeders\E2eSeeder' --force
  compose exec -T \
    -e E2E_WEB_API_KEY -e E2E_TENANT_DOMAINS="$domains" -e E2E_POS_URL="http://pos:8000" \
    -e E2E_PAYMOB_SECRET_KEY -e E2E_PAYMOB_PUBLIC_KEY -e E2E_PAYMOB_INTEGRATION_IDS -e E2E_PAYMOB_HMAC_SECRET \
    site php /e2e-seed/site.php
  # The website promotes the seeded customer when asked; tests that need /admin use this account.
  site_artisan tinker --execute="tenancy()->initialize('e2e'); App\\Models\\User::firstOrCreate(['email'=>'store-admin@example.com'],['name'=>'E2E Store Admin','password'=>'password','email_verified_at'=>now(),'is_admin'=>true]);" >/dev/null

  {
    echo "SITE_URL=http://e2e.localhost:${SITE_PORT}"
    echo "POS_URL=http://localhost:${POS_PORT}"
    [ -n "$tunnel" ] && echo "SITE_PUBLIC_URL=$tunnel"
  } > .e2e/urls.env
  log "Seeded. URLs written to e2e/.e2e/urls.env"
  [ -n "$tunnel" ] && log "Paymob callbacks reach: $tunnel/api/webhooks/paymob"
  [ -z "${E2E_PAYMOB_SECRET_KEY:-}" ] && log "No Paymob test keys in e2e/.env.e2e, so online payment stays off."
  return 0
}

cmd_test() {
  if has_flag --docker "$@"; then
    local rest=()
    for a in "$@"; do [ "$a" = --docker ] || rest+=("$a"); done
    compose --profile runner run --rm --build runner run "${rest[@]}"
    return
  fi
  command -v node >/dev/null || die "Node 24 is needed (inside WSL on Windows), or use: stack.sh test --docker"
  [ -d node_modules ] || { log "Installing e2e packages (~20 MB, once)"; npm install --no-audit --no-fund; }
  [ -f .e2e/urls.env ] && { set -a; . .e2e/urls.env; set +a; }
  npx e2e run "$@"
}

case "${1:-help}" in
  install) shift; cmd_install "$@" ;;
  up) shift; cmd_up "$@" ;;
  seed) shift; cmd_seed "$@" ;;
  test) shift; cmd_test "$@" ;;
  tunnel-url) tunnel_url || die "No tunnel URL yet. Is the stack up with --tunnel?" ;;
  logs) shift; compose --profile tunnel logs -f --tail=100 "$@" ;;
  status) compose --profile tunnel ps; echo "mode: $(mode)" ;;
  down)
    shift
    if has_flag --wipe "$@"; then compose --profile tunnel --profile runner down -v; else compose --profile tunnel --profile runner down; fi ;;
  *) sed -n '2,13p' "$0" ;;
esac
