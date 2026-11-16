# Local setup

## Requirements

- PHP 8.4 (8.3 works for tests) with `pdo_mysql`, `intl`, `gd`, `zip`, `mbstring`, `pcntl`
- Composer 2, Node 20+
- MySQL 8 (local dev uses port 8085 in the original setup)
- Optional: Chromium for Browsershot receipts, or the bundled `wkhtmltoimage`

## Install

```bash
cd larament
composer install
npm install
cp .env.example .env
php artisan key:generate
# edit DB_*, REVERB_* in .env
php artisan migrate --seed
php artisan pos:vendor          # keep the printed password
npm run build                   # or npm run dev
```

## Run

```bash
composer dev
```

runs `php artisan serve`, `queue:listen`, `pail` (logs) and `vite` together. Start Reverb separately with `php artisan reverb:start` if you need live updates.

Log in at `/admin/login` with `admin@example.com` / `password`, or as the vendor to run the setup wizard.

## Claude Code cloud sessions

The cloud proxy blocks GitHub zip downloads, so a plain `composer install` takes 10+ minutes. Use:

```bash
bash larament/scripts/cloud-setup.sh   # ~30 s
cd larament && php vendor/bin/pest --parallel
```

Branches older than PR #7 need `git checkout origin/feature/modular-core -- larament/scripts` first.

## This documentation

```bash
cd docs
npm install
npm run dev      # http://localhost:5173
npm run build    # static site in docs/.vitepress/dist
```
