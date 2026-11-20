# Ensure scheduler cron entry exists: * * * * * cd /var/www/turbo_restaurant/larament && php artisan schedule:run >> /dev/null 2>&1
php artisan config:clear
php artisan cache:clear
php artisan config:cache
rc-service php-fpm83 restart
rc-service php-fpm84 restart || rc-service php-fpm83 restart
rc-service nginx restart
npm run build
php artisan queue:restart  # if applicable

