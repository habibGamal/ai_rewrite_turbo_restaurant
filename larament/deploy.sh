#!/bin/sh
set -e
cd ../
git reset --hard
git pull
cd larament
composer install
npm install
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize:clear
php artisan optimize
