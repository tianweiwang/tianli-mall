#!/bin/sh
set -eu

/usr/local/bin/php -d open_basedir= -d display_errors=0 -d display_startup_errors=0 -d log_errors=0 /usr/local/bin/marketplace-startup-check.php

mkdir -p /var/www/runtime /var/www/public/uploads
chown -R www-data:www-data /var/www/runtime /var/www/public/uploads
rm -f /var/www/runtime/workerman.pid /var/www/runtime/timer.pid

exec docker-php-entrypoint "$@"
